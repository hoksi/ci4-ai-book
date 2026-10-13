<?php

namespace App\Services;

use App\Models\SpamKeywordModel;

/**
 * 4단계 스팸 감지 서비스
 *
 * 1단계: 규칙 기반 (내장 키워드 + DB 학습 키워드)
 * 2단계: StopForumSpam IP 평판 체크 (무료, 30분 캐시)
 * 3단계: Groq AI (점수 31~69 불확실 구간만)
 * 결과: approved / review / spam
 */
class SpamChecker
{
    private const SCORE_SPAM   = 70;
    private const SCORE_REVIEW = 30;
    private const GROQ_API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const SFS_API_URL  = 'https://api.stopforumspam.org/api';
    private const IP_LIMIT     = 5;
    private const IP_WINDOW    = 600;

    public const BUILTIN_KEYWORDS = [
        '비아그라', '시알리스', '카지노', '도박', '복권', '대출', '빚탕감',
        'casino', 'viagra', 'cialis', 'porn', 'sex', 'loan', 'click here',
        'free money', 'winner', 'congratulations', '무료수익', '부업',
        '당첨', '클릭', '지금바로', '100%보장',
    ];

    /** @return string[] */
    public static function builtinKeywords(): array
    {
        return self::BUILTIN_KEYWORDS;
    }

    /**
     * @return array{status: string, score: int, reason: string, sfs?: array{appears: bool, confidence: float, frequency: int, torexit: bool, score: int}, keywords?: string[]}
     */
    public function check(string $title, string $content, string $ip): array
    {
        $text      = $title . ' ' . $content;
        $ruleScore = $this->ruleScore($text, $ip);
        $sfs       = $this->checkStopForumSpam($ip);
        $score     = min($ruleScore + $sfs['score'], 100);

        if ($score >= self::SCORE_SPAM) {
            $reason = $sfs['appears'] && $sfs['score'] >= 20
                ? "StopForumSpam 차단 IP (신뢰도 {$sfs['confidence']}%)"
                : '규칙 기반 스팸 감지';

            return ['status' => 'spam', 'score' => $score, 'reason' => $reason, 'sfs' => $sfs];
        }

        if ($score <= self::SCORE_REVIEW) {
            return ['status' => 'approved', 'score' => $score, 'reason' => '정상', 'sfs' => $sfs];
        }

        return $this->aiCheck($title, $content, $score, $sfs);
    }

    private function ruleScore(string $text, string $ip): int
    {
        $score     = 0;
        $lowerText = mb_strtolower($text);

        foreach ((new SpamKeywordModel())->getActiveKeywords() as $kw) {
            if (str_contains($lowerText, $kw)) {
                $score += 30;
                break;
            }
        }

        $urlCount = preg_match_all('/https?:\/\/\S+/i', $text);
        if ($urlCount >= 3) {
            $score += 40;
        } elseif ($urlCount >= 2) {
            $score += 20;
        }

        if (preg_match('/(.)\1{4,}/', $text)) {
            $score += 20;
        }

        $specialCount = preg_match_all('/[!@#$%^&*(){}\[\]<>\/\\\\|~`]/', $text);
        $totalLen      = mb_strlen($text) ?: 1;
        if ($specialCount / $totalLen > 0.3) {
            $score += 25;
        }

        if ($this->isIpOverLimit($ip)) {
            $score += 35;
        }

        return min($score, 100);
    }

    private function isIpOverLimit(string $ip): bool
    {
        $cacheKey = 'spam_ip_' . md5($ip);
        $count    = cache($cacheKey) ?? 0;

        cache()->save($cacheKey, $count + 1, self::IP_WINDOW);

        return $count >= self::IP_LIMIT;
    }

    /**
     * @return array{appears: bool, confidence: float, frequency: int, torexit: bool, score: int}
     */
    private function checkStopForumSpam(string $ip): array
    {
        $cacheKey = 'sfs_ip_' . md5($ip);
        $cached   = cache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $default = ['appears' => false, 'confidence' => 0.0, 'frequency' => 0, 'torexit' => false, 'score' => 0];

        $url = self::SFS_API_URL . '?ip=' . urlencode($ip) . '&json';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_USERAGENT      => 'CI4-Playground/1.0',
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $raw === false) {
            cache()->save($cacheKey, $default, 1800);

            return $default;
        }

        try {
            $data       = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            $ipData     = $data['ip'] ?? [];
            $appears    = (bool) ($ipData['appears'] ?? false);
            $confidence = (float) ($ipData['confidence'] ?? 0.0);
            $frequency  = (int) ($ipData['frequency'] ?? 0);
            $torexit    = (bool) ($ipData['torexit'] ?? false);

            $sfsScore = 0;
            if ($appears) {
                if ($confidence >= 90) {
                    $sfsScore = 40;
                } elseif ($confidence >= 60) {
                    $sfsScore = 20;
                } else {
                    $sfsScore = 10;
                }
            }
            if ($torexit) {
                $sfsScore = max($sfsScore, 15);
            }

            $result = [
                'appears'    => $appears,
                'confidence' => $confidence,
                'frequency'  => $frequency,
                'torexit'    => $torexit,
                'score'      => $sfsScore,
            ];
        } catch (\JsonException $e) {
            $result = $default;
        }

        cache()->save($cacheKey, $result, 1800);

        return $result;
    }

    /**
     * @param array{appears: bool, confidence: float, frequency: int, torexit: bool, score: int} $sfs
     * @return array{status: string, score: int, reason: string, sfs: array{appears: bool, confidence: float, frequency: int, torexit: bool, score: int}, keywords?: string[]}
     */
    private function aiCheck(string $title, string $content, int $ruleScore, array $sfs): array
    {
        $apiKey = env('GROQ_API_KEY');
        if (empty($apiKey)) {
            return ['status' => 'approved', 'score' => $ruleScore, 'reason' => 'AI 키 미설정', 'sfs' => $sfs];
        }

        $prompt = <<<PROMPT
다음 게시글이 스팸인지 판단해줘. JSON으로만 응답해.

제목: {$title}
내용: {$content}

응답 형식: {"is_spam": true|false, "reason": "한 줄 이유", "keywords": ["핵심스팸단어1", "단어2"]}
keywords는 스팸일 때만 2~5개 추출해. 아니면 빈 배열.
PROMPT;

        $payload = json_encode([
            'model'       => config('PlaygroundConfig')->groqChatModel,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'temperature'      => 0,
            'max_tokens'       => 120,
            'reasoning_effort' => 'low',
        ]);

        $ch = curl_init(self::GROQ_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $raw === false) {
            return ['status' => 'review', 'score' => $ruleScore, 'reason' => 'AI 판정 실패', 'sfs' => $sfs];
        }

        try {
            $response = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            $aiText   = $response['choices'][0]['message']['content'] ?? '{}';

            if (preg_match('/\{.*\}/s', $aiText, $m)) {
                $aiText = $m[0];
            }

            $aiResult = json_decode($aiText, true, 512, JSON_THROW_ON_ERROR);
            $isSpam   = (bool) ($aiResult['is_spam'] ?? false);
            $reason   = (string) ($aiResult['reason'] ?? 'AI 판정');
            $keywords = $aiResult['keywords'] ?? [];

            if ($isSpam && is_array($keywords)) {
                $model = new SpamKeywordModel();
                foreach ($keywords as $kw) {
                    if (is_string($kw)) {
                        $model->saveOrIncrement($kw);
                    }
                }
            }

            return [
                'status'   => $isSpam ? 'spam' : 'approved',
                'score'    => $ruleScore,
                'reason'   => $reason,
                'sfs'      => $sfs,
                'keywords' => is_array($keywords) ? $keywords : [],
            ];
        } catch (\JsonException $e) {
            return ['status' => 'review', 'score' => $ruleScore, 'reason' => 'AI 응답 파싱 실패', 'sfs' => $sfs];
        }
    }
}
