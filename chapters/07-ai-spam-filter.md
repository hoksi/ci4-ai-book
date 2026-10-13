# 7장. AI 스팸 필터 만들기

## 이번 장에서 만드는 것

4장에서 만든 게시판에 스팸 필터를 붙입니다. 글이 등록될 때마다 자동으로 스팸 여부를 판단합니다. 정상인 게 확실하면 바로 노출하고, 스팸인 게 확실하면 차단하고, 애매하면 사람이 나중에 검토하도록 넘깁니다.

## 설계의 핵심: AI를 모든 글에 쓰지 않는다

가장 단순한 설계는 "글이 등록될 때마다 AI에게 스팸인지 물어본다"일 겁니다. 하지만 이건 두 가지 문제가 있습니다 — **모든 요청마다 외부 API 호출이 추가되어 느려지고**, **트래픽이 늘수록 AI 호출 비용과 쿼터 소모가 비례해서 늘어납니다.**

그래서 3단계 점수 기반 파이프라인으로 설계합니다.

1. **규칙 기반 점수 계산** (즉시, 무료) — 키워드, URL 개수, IP 반복 작성 등으로 0\~100점 산출
2. **외부 IP 평판 조회** (무료 API, 캐시) — 알려진 스팸 IP인지 확인
3. **점수가 애매한 구간(31\~69점)일 때만 AI 호출** — 명확한 경우는 AI를 아예 건드리지 않음

```php
public function check(string $title, string $content, string $ip): array
{
    $text      = $title . ' ' . $content;
    $ruleScore = $this->ruleScore($text, $ip);
    $sfs       = $this->checkStopForumSpam($ip);
    $score     = min($ruleScore + $sfs['score'], 100);

    if ($score >= 70) {
        return ['status' => 'spam', 'score' => $score, 'reason' => '규칙 기반 스팸 감지'];
    }
    if ($score <= 30) {
        return ['status' => 'approved', 'score' => $score, 'reason' => '정상'];
    }

    return $this->aiCheck($title, $content, $score, $sfs); // 애매할 때만 AI 호출
}
```

이 패턴 — **"저렴하고 빠른 방법으로 먼저 거르고, 정말 판단이 어려운 경우에만 비싼 AI를 쓴다"** — 는 AI 기능을 실전에 넣을 때 반복해서 쓰게 될 설계입니다. 꼭 스팸 필터가 아니어도, "모든 요청에 LLM을 쓸 것인가, 일부에만 쓸 것인가"는 항상 먼저 고민해볼 가치가 있습니다.

## 1단계: 규칙 기반 점수

```php
private function ruleScore(string $text, string $ip): int
{
    $score = 0;
    $lowerText = mb_strtolower($text);

    foreach ((new SpamKeywordModel())->getActiveKeywords() as $kw) {
        if (str_contains($lowerText, $kw)) { $score += 30; break; }
    }

    $urlCount = preg_match_all('/https?:\/\/\S+/i', $text);
    if ($urlCount >= 3) $score += 40;
    elseif ($urlCount >= 2) $score += 20;

    if (preg_match('/(.)\1{4,}/', $text)) $score += 20; // 같은 글자 5번 이상 반복

    $specialCount = preg_match_all('/[!@#$%^&*(){}\[\]<>\/\\\\|~`]/', $text);
    if ($specialCount / (mb_strlen($text) ?: 1) > 0.3) $score += 25;

    if ($this->isIpOverLimit($ip)) $score += 35; // 짧은 시간에 반복 작성

    return min($score, 100);
}
```

흥미로운 부분은 `isIpOverLimit()`입니다. 캐시를 "카운터"로 활용합니다.

```php
private function isIpOverLimit(string $ip): bool
{
    $cacheKey = 'spam_ip_' . md5($ip);
    $count    = cache($cacheKey) ?? 0;

    cache()->save($cacheKey, $count + 1, 600); // 10분 동안 유지

    return $count >= 5; // 10분 내 5번째 작성부터 감점
}
```

DB 테이블 없이, 캐시 값 하나를 "최근 N분간 요청 횟수"로 쓰는 건 흔한 트릭입니다. 11장의 `Throttler`도 내부적으로 비슷한 원리입니다.

`SpamKeywordModel::getActiveKeywords()`가 돌려주는 키워드는 내장 목록(`비아그라`, `casino`, `무료수익`...)뿐 아니라, 뒤에서 다룰 "AI가 찾아낸 스팸 단어"도 함께 포함됩니다 — 이게 이 설계의 또 다른 핵심입니다.

## 2단계: 외부 IP 평판 조회

[StopForumSpam](https://www.stopforumspam.com/)은 무료로 "이 IP가 스팸 활동 이력이 있는지" 조회할 수 있는 공개 API입니다.

```php
private function checkStopForumSpam(string $ip): array
{
    $cacheKey = 'sfs_ip_' . md5($ip);
    if ($cached = cache($cacheKey)) return $cached;

    $ch = curl_init(self::SFS_API_URL . '?ip=' . urlencode($ip) . '&json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $default = ['appears' => false, 'confidence' => 0.0, 'score' => 0];
    if ($raw === false) {
        cache()->save($cacheKey, $default, 1800);
        return $default; // 외부 서비스가 응답 안 해도 글쓰기 자체는 막지 않는다
    }

    // ... 응답 파싱, confidence에 따라 점수 산출 ...

    cache()->save($cacheKey, $result, 1800); // 30분 캐시
    return $result;
}
```

여기서 중요한 설계 원칙 하나 — **외부 서비스가 실패하거나 타임아웃 나도, 글쓰기 기능 자체는 멈추지 않습니다.** 실패 시 "스팸 아님"에 해당하는 기본값을 돌려주고 넘어갑니다. 스팸 필터 같은 부가 기능이 핵심 기능(글쓰기)의 가용성까지 끌어내리면 안 됩니다. `CURLOPT_TIMEOUT => 3`으로 짧게 잡은 것도 같은 이유입니다 — 외부 서비스가 느려도 사용자를 3초 이상 기다리게 하지 않습니다.

## 3단계: 애매한 경우만 AI에게

점수가 31\~69점(규칙으로는 확신할 수 없는 구간)일 때만 AI를 호출합니다. 이때 중요한 건 **AI에게 자유 형식으로 답하게 하지 않고, JSON 형식을 강제**하는 것입니다.

```php
private function aiCheck(string $title, string $content, int $ruleScore, array $sfs): array
{
    $prompt = <<<PROMPT
다음 게시글이 스팸인지 판단해줘. JSON으로만 응답해.

제목: {$title}
내용: {$content}

응답 형식: {"is_spam": true|false, "reason": "한 줄 이유", "keywords": ["핵심스팸단어1", "단어2"]}
keywords는 스팸일 때만 2~5개 추출해. 아니면 빈 배열.
PROMPT;

    $payload = json_encode([
        'model'            => config('PlaygroundConfig')->groqChatModel,
        'messages'         => [['role' => 'user', 'content' => $prompt]],
        'temperature'      => 0,     // 판정은 매번 같은 결과가 나와야 하므로 0
        'max_tokens'       => 120,
        'reasoning_effort' => 'low',
    ]);

    // ... curl 호출 ...

    $aiResult = json_decode($aiText, true);
    $isSpam   = (bool) ($aiResult['is_spam'] ?? false);
    $keywords = $aiResult['keywords'] ?? [];

    // AI가 찾아낸 스팸 단어를 학습시켜서, 다음엔 1단계 규칙만으로 걸러지게 한다
    if ($isSpam && is_array($keywords)) {
        $model = new SpamKeywordModel();
        foreach ($keywords as $kw) {
            if (is_string($kw)) $model->saveOrIncrement($kw);
        }
    }

    return ['status' => $isSpam ? 'spam' : 'approved', 'reason' => $aiResult['reason'] ?? 'AI 판정'];
}
```

6장에서 짚었듯, 스팸 판정은 **같은 입력이면 항상 같은 결과**가 나와야 신뢰할 수 있는 시스템이 되므로 `temperature`를 0으로 가장 낮게 잡았습니다. 뒤에서 만들 챗봇처럼 매번 다른 표현이 나와도 되는 작업과는 정반대입니다. "창의성이 필요한 작업인가, 일관성이 필요한 작업인가"에 따라 `temperature`를 다르게 가져가는 건 실전에서 자주 마주치는 선택입니다.

가장 중요한 부분은 **AI가 찾아낸 스팸 키워드를 다시 1단계 규칙에 학습시키는 피드백 루프**입니다. 처음 보는 스팸 패턴은 AI가 잡아내지만, 똑같은 패턴이 또 나타나면 이젠 AI 호출 없이 1단계 키워드 매칭만으로 바로 걸러집니다. 시간이 지날수록 AI 호출 빈도가 줄어드는 자가 학습 구조입니다.

## 게시판에 연결하기

```php
public function store()
{
    // ... 유효성 검사 (5장) ...

    $spamStatus = 'approved';
    $spam = (new SpamChecker())->check($title, $content, $this->request->getIPAddress());

    if ($spam['status'] === 'spam') {
        return redirect()->back()->withInput()->with('error', '스팸으로 감지된 게시글입니다.');
    }

    $spamStatus = $spam['status']; // 'approved' 또는 'review'

    $this->model->insert([
        'title' => $title, 'content' => $content, 'author' => $author,
        'spam_status' => $spamStatus,
    ]);

    $message = $spamStatus === 'review'
        ? '게시글이 등록되었습니다. (검토 후 노출될 수 있습니다.)'
        : '게시글이 작성되었습니다.';

    return redirect()->to(base_url('board'))->with('success', $message);
}
```

`posts` 테이블에 `spam_status` 컬럼을 추가합니다(`approved`/`review`/`spam`). 게시글 목록(`index()`)에서는 `where('spam_status', 'approved')`로 필터링해서, `review` 상태인 글은 관리자가 확인하기 전까지 일반 사용자에게 보이지 않게 합니다.

## 사람이 최종 판단: 관리자 검토 화면

`status === 'spam'`이면 등록 자체를 막지만, `review`(애매함)는 **일단 등록은 되지만 숨겨둔 채로, 사람이 최종 결정**하게 합니다. AI를 전적으로 신뢰해서 완전 자동화하는 대신, 판단이 불확실한 영역에는 사람을 끼워 넣는 겁니다.

```php
public function index(): string
{
    $reviewPosts = $this->postModel->where('spam_status', 'review')->orderBy('created_at', 'DESC')->findAll();
    return view('examples/spam-admin/index', ['reviewPosts' => $reviewPosts, /* ... */]);
}

public function approvePost(int $id)
{
    $this->postModel->update($id, ['spam_status' => 'approved']);
    return redirect()->to(base_url('spam-admin'))->with('success', '게시글이 승인되었습니다.');
}

public function spamPost(int $id)
{
    $this->postModel->update($id, ['spam_status' => 'spam']);
    return redirect()->to(base_url('spam-admin'))->with('success', '게시글이 스팸으로 처리되었습니다.');
}
```

AI를 신뢰할 수 없는 영역에 쓸 때는, "AI가 전부 결정한다"보다 "AI는 사람이 검토할 후보를 추려준다"는 역할로 쓰는 게 훨씬 안전합니다.

## 한계

- StopForumSpam처럼 무료 외부 서비스에 의존하면, 그 서비스 자체의 가용성/정책 변경에 영향을 받습니다. 이번 장에서 본 것처럼 "실패하면 안전한 기본값으로 넘어간다"는 원칙을 항상 지키세요.
- AI 판정 자체도 결국 Groq 호출이라 11장에서 다루는 쿼터 초과 문제에서 자유롭지 않습니다. `aiCheck()`에서 호출이 실패하면 `review`로 분류해서 사람 검토로 넘기는 것도 "실패 시 안전한 쪽으로" 원칙의 연장입니다.

## 체크리스트

- [ ] 스팸 키워드가 포함된 글을 작성하면 차단되는가
- [ ] 애매한 글(점수 31\~69)이 바로 노출되지 않고 `review` 상태로 남는가
- [ ] AI가 찾아낸 키워드가 `spam_keywords` 테이블에 쌓여서, 같은 패턴이 다음부턴 AI 없이 걸러지는가
- [ ] StopForumSpam API를 일부러 접속 불가로 만들어도(방화벽 등) 글쓰기 자체는 여전히 동작하는가

## 전체 코드

[`examples/07-ai-spam-filter/`](../examples/07-ai-spam-filter/)에서 전체 원본을 받아볼 수 있습니다.

다음 장에서는 이 게시판 글 자체를 검색 대상으로 삼아, 질문과 관련된 글을 찾아 AI에게 근거로 넘기는 RAG(검색 증강 생성) 패턴을 다룹니다. 방금 만든 `spam_status` 컬럼도 거기서 다시 쓰입니다.
