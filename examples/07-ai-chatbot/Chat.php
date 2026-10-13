<?php

namespace App\Controllers\Examples;

use App\Controllers\BaseController;

class Chat extends BaseController
{
    private const BOT_NICK  = 'AI봇';

    private const SEARCH_KEYWORDS = [
        '최신', '오늘', '현재', '지금', '어제', '이번 주', '이번 달',
        '뉴스', '날씨', '주가', '환율', '최근', '요즘', '지금 몇 시',
    ];

    public function index(): string
    {
        $messages = db_connect()
            ->table('chat_messages')
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        return view('examples/chat/index', [
            'title'    => '챗봇',
            'messages' => array_reverse($messages),
        ]);
    }

    /** 유저 메시지 저장 + (검색) + Groq 봇 응답 저장 → 반환 */
    public function send(): \CodeIgniter\HTTP\ResponseInterface
    {
        $content = trim((string) ($this->request->getPost('content') ?? ''));
        $apiKey  = trim((string) ($this->request->getPost('api_key') ?? ''));

        if ($content === '') {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => '메시지를 입력해주세요.']);
        }

        if (mb_strlen($content) > 500) {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => '메시지는 500자 이내로 입력해주세요.']);
        }

        if ($apiKey === '') {
            $apiKey = trim((string) (env('GROQ_API_KEY') ?? ''));
        }

        if ($apiKey === '') {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => 'Groq API 키가 필요합니다.']);
        }

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // 유저 메시지 저장
        $userId  = $db->table('chat_messages')->insert([
            'nickname'   => '나',
            'content'    => $content,
            'created_at' => $now,
        ], true);
        $userMsg = ['id' => $userId, 'nickname' => '나', 'content' => $content, 'created_at' => $now];

        // 웹 검색 (키워드 감지)
        $searched      = false;
        $sources       = [];
        $searchContext = '';

        if ($this->needsSearch($content)) {
            $result  = $this->searchWeb($content);
            $organic = $result['organic'] ?? [];

            if (! empty($organic)) {
                $searched = true;
                $sources  = array_slice(array_column($organic, 'link'), 0, 3);

                $searchContext = "\n\n[최신 웹 검색 결과 — 답변 시 참고하세요]\n";
                foreach (array_slice($organic, 0, 3) as $r) {
                    $searchContext .= "• {$r['title']}: {$r['snippet']}\n";
                }

                // knowledgeGraph 추가
                $kg = $result['knowledgeGraph'] ?? [];
                if (! empty($kg['description'])) {
                    $searchContext .= "• 지식 패널: {$kg['description']}\n";
                }
            }
        }

        // 최근 20개 대화 컨텍스트
        $history = $db->table('chat_messages')
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        $systemPrompt = '당신은 친근하고 유쾌한 AI 어시스턴트입니다. 한국어로 자연스럽게 대화하세요. 2~3문장 이내로 답변하세요.'
            . $searchContext;

        $groqMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_reverse($history) as $msg) {
            $groqMessages[] = [
                'role'    => $msg['nickname'] === self::BOT_NICK ? 'assistant' : 'user',
                'content' => $msg['content'],
            ];
        }

        try {
            $response = \Config\Services::curlrequest()->post(
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode([
                        'model'       => config('PlaygroundConfig')->groqChatModel,
                        'messages'    => $groqMessages,
                        'max_tokens'       => 300,
                        'temperature'      => 0.8,
                        'reasoning_effort' => 'low',
                    ]),
                    'timeout' => 15,
                ]
            );

            $body       = json_decode($response->getBody(), true);
            $botContent = trim($body['choices'][0]['message']['content'] ?? '');

            if ($botContent === '') {
                return $this->response->setStatusCode(500)
                    ->setJSON(['error' => 'AI 응답이 비어 있습니다.', 'user' => $userMsg]);
            }

            $botNow = date('Y-m-d H:i:s');
            $botId  = $db->table('chat_messages')->insert([
                'nickname'   => self::BOT_NICK,
                'content'    => $botContent,
                'created_at' => $botNow,
            ], true);

            return $this->response->setJSON([
                'success'  => true,
                'user'     => $userMsg,
                'bot'      => ['id' => $botId, 'nickname' => self::BOT_NICK, 'content' => $botContent, 'created_at' => $botNow],
                'searched' => $searched,
                'sources'  => $sources,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)
                ->setJSON(['error' => 'Groq API 오류: ' . $e->getMessage(), 'user' => $userMsg]);
        }
    }

    public function clear(): \CodeIgniter\HTTP\ResponseInterface
    {
        db_connect()->table('chat_messages')->truncate();
        return $this->response->setJSON(['success' => true]);
    }

    // ─── private helpers ─────────────────────────────────────────────────────

    /** 키워드 감지 — 최신 정보가 필요한 질문인지 판단 */
    private function needsSearch(string $content): bool
    {
        if (trim(env('SERPER_API_KEY') ?? '') === '') {
            return false;
        }

        foreach (self::SEARCH_KEYWORDS as $kw) {
            if (str_contains($content, $kw)) return true;
        }

        // 연도 패턴 (2024, 2025, 2026 등)
        return (bool) preg_match('/20\d{2}년?/', $content);
    }

    /** Serper Google 검색 (10분 캐시) */
    private function searchWeb(string $query): array
    {
        $apiKey = trim((string) (env('SERPER_API_KEY') ?? ''));
        if ($apiKey === '') return [];

        // 캐시 확인
        $cacheKey = 'serper_' . md5($query);
        $cached   = cache($cacheKey);
        if ($cached !== null) return $cached;

        try {
            $res = \Config\Services::curlrequest()->post(
                'https://google.serper.dev/search',
                [
                    'headers' => [
                        'X-API-KEY'    => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => json_encode([
                        'q'   => $query,
                        'num' => 5,
                        'gl'  => 'kr',
                        'hl'  => 'ko',
                    ]),
                    'timeout' => 10,
                ]
            );

            $result = json_decode($res->getBody(), true) ?? [];
            cache()->save($cacheKey, $result, 600); // 10분
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
