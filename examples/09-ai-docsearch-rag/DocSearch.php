<?php

namespace App\Controllers\Examples;

use App\Controllers\BaseController;

/**
 * CI4 한국어 매뉴얼 AI 검색봇.
 *
 * app/Data/docsearch_index.json (php spark docsearch:build 로 생성) 에서
 * 질문과 관련된 매뉴얼 페이지를 키워드 점수로 찾아 Groq에게 근거로 넘기고,
 * 매뉴얼 내용에 기반한 답변 + 출처 링크를 반환한다.
 */
class DocSearch extends BaseController
{
    private const INDEX_FILE  = APPPATH . 'Data/docsearch_index.json';
    private const TOP_N       = 5;
    private const CONTEXT_MAX = 6000;

    /** 질문에서 걸러낼 의미 없는 토큰 */
    private const STOPWORDS = [
        '어떻게', '어떤', '방법', '알려주세요', '알려줘', '설명해주세요', '설명해줘',
        '뭐예요', '뭔가요', '무엇인가요', '있나요', '하나요', '되나요', '해주세요',
        '하려면', '사용법', '좀', '그', '이', '저', '합니다', '하는', '사용하는', '사용할',
        'what', 'how', 'is', 'the', 'a', 'to', 'of', 'for', 'in', 'on', 'does', 'do', 'can',
    ];

    public function index(): string
    {
        $logs = db_connect()
            ->table('doc_search_logs')
            ->orderBy('id', 'DESC')
            ->limit(10)
            ->get()
            ->getResultArray();

        foreach ($logs as &$log) {
            $log['sources'] = json_decode($log['sources'] ?? '[]', true) ?? [];
        }
        unset($log);

        return view('examples/docsearch/index', [
            'title' => 'CI4 매뉴얼 AI 검색봇',
            'logs'  => array_reverse($logs),
        ]);
    }

    public function ask(): \CodeIgniter\HTTP\ResponseInterface
    {
        $question = trim((string) ($this->request->getPost('question') ?? ''));
        $apiKey   = trim((string) ($this->request->getPost('api_key') ?? ''));

        if ($question === '') {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => '질문을 입력해주세요.']);
        }

        if (mb_strlen($question) > 300) {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => '질문은 300자 이내로 입력해주세요.']);
        }

        if ($apiKey === '') {
            $apiKey = trim((string) (env('GROQ_API_KEY') ?? ''));
        }

        if ($apiKey === '') {
            return $this->response->setStatusCode(400)
                ->setJSON(['error' => 'Groq API 키가 필요합니다.']);
        }

        $matches = $this->searchDocs($question);

        if (empty($matches)) {
            return $this->response->setJSON([
                'success'  => true,
                'question' => $question,
                'answer'   => '관련된 매뉴얼 문서를 찾지 못했습니다. 다른 질문어로 다시 시도해보세요.',
                'sources'  => [],
            ]);
        }

        [$context, $sources] = $this->buildContext($matches);

        $systemPrompt = <<<PROMPT
            당신은 CodeIgniter 4 한국어 공식 매뉴얼 기반 Q&A 도우미입니다.
            아래 [매뉴얼 발췌]에 있는 내용만 근거로 한국어로 답변하세요.
            발췌 내용에 답이 없으면 "매뉴얼에서 관련 내용을 찾지 못했습니다"라고 답하세요.
            코드 예제가 있다면 포함하고, 4~6문장 이내로 간결하게 답변하세요.

            [매뉴얼 발췌]
            {$context}
            PROMPT;

        try {
            $response = \Config\Services::curlrequest()->post(
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode([
                        'model'    => config('PlaygroundConfig')->groqChatModel,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $question],
                        ],
                        'max_tokens'       => 500,
                        'temperature'      => 0.3,
                        'reasoning_effort' => 'low',
                    ]),
                    'timeout' => 20,
                ]
            );

            $body   = json_decode($response->getBody(), true);
            $answer = trim($body['choices'][0]['message']['content'] ?? '');

            if ($answer === '') {
                return $this->response->setStatusCode(500)
                    ->setJSON(['error' => 'AI 응답이 비어 있습니다.']);
            }

            db_connect()->table('doc_search_logs')->insert([
                'question'   => $question,
                'answer'     => $answer,
                'sources'    => json_encode($sources, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'success'  => true,
                'question' => $question,
                'answer'   => $answer,
                'sources'  => $sources,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)
                ->setJSON(['error' => 'Groq API 오류: ' . $e->getMessage()]);
        }
    }

    public function clear(): \CodeIgniter\HTTP\ResponseInterface
    {
        db_connect()->table('doc_search_logs')->truncate();
        return $this->response->setJSON(['success' => true]);
    }

    // ─── private helpers ─────────────────────────────────────────────────────

    /** @return array<int, array{doc: array, score: float, bestToken: string}> */
    private function searchDocs(string $question): array
    {
        static $index = null;

        if ($index === null) {
            $index = is_file(self::INDEX_FILE)
                ? (json_decode(file_get_contents(self::INDEX_FILE), true) ?? [])
                : [];
        }

        $tokens = $this->tokenize($question);

        if (empty($tokens) || empty($index)) {
            return [];
        }

        $results = [];

        foreach ($index as $doc) {
            $titleLower = mb_strtolower($doc['title']);
            $textLower  = mb_strtolower($doc['text']);

            $score     = 0.0;
            $bestToken = '';
            $bestCount = 0;

            foreach ($tokens as $tok => $weight) {
                $titleHits = substr_count($titleLower, $tok);
                $textHits  = substr_count($textLower, $tok);

                if ($titleHits + $textHits > $bestCount) {
                    $bestCount = $titleHits + $textHits;
                    $bestToken = $tok;
                }

                $score += ($titleHits * 5 + $textHits) * $weight;
            }

            if ($score > 0) {
                $results[] = ['doc' => $doc, 'score' => $score, 'bestToken' => $bestToken];
            }
        }

        usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, self::TOP_N);
    }

    /** @return array<string, float> 토큰 => 가중치 */
    private function tokenize(string $question): array
    {
        $raw = preg_split('/[\s,.\?!\/\\\\·"\'()\[\]{}]+/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];

        foreach ($raw as $word) {
            $word = mb_strtolower($word);

            if (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $tokens[$word] = max($tokens[$word] ?? 0, 1.0);

            // 한국어 조사/어미가 붙은 경우를 대비해 끝 1~2글자를 떼어낸 변형도 약한 가중치로 포함
            $len = mb_strlen($word);
            if ($len >= 4) {
                $trim1 = mb_substr($word, 0, $len - 1);
                $tokens[$trim1] = max($tokens[$trim1] ?? 0, 0.6);
            }
            if ($len >= 5) {
                $trim2 = mb_substr($word, 0, $len - 2);
                $tokens[$trim2] = max($tokens[$trim2] ?? 0, 0.4);
            }
        }

        return $tokens;
    }

    /**
     * @param array<int, array{doc: array, score: float, bestToken: string}> $matches
     * @return array{0: string, 1: array<int, array{title: string, path: string, url: string}>}
     */
    private function buildContext(array $matches): array
    {
        $context = '';
        $sources = [];

        foreach ($matches as $m) {
            $doc = $m['doc'];
            $pos = $m['bestToken'] !== '' ? mb_stripos($doc['text'], $m['bestToken']) : false;

            $snippet = $pos !== false
                ? mb_substr($doc['text'], max(0, $pos - 150), 900)
                : mb_substr($doc['text'], 0, 900);

            $block = "### {$doc['title']}\n{$snippet}\n\n";

            if (mb_strlen($context) + mb_strlen($block) > self::CONTEXT_MAX) {
                break;
            }

            $context  .= $block;
            $sources[] = [
                'title' => $doc['title'],
                'path'  => $doc['path'],
                'url'   => base_url('docs/Codeigniter4/' . $doc['path']),
            ];
        }

        return [$context, $sources];
    }
}
