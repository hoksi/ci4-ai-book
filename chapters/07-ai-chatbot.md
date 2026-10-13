# 7장. AI 챗봇 만들기

## 이번 장에서 만드는 것

6장에서는 메시지 하나를 보내고 응답 하나를 받는, 1회성 호출만 해봤습니다. 실제 챗봇은 "방금 뭐라고 했는지"를 기억하고 대화를 이어갑니다. 이번 장에서는 메시지를 DB에 쌓아두고, 매 요청마다 최근 대화 내역을 같이 보내서 맥락이 유지되는 챗봇을 만듭니다.

## LLM은 아무것도 기억하지 못한다

중요한 전제부터 짚고 갑니다 — Groq 같은 LLM API는 **완전히 무상태**(stateless)입니다. "아까 내가 한 말 기억해?"라고 물어도, 서버가 그 이전 요청을 저장해뒀다가 참조해주는 게 아닙니다. **대화 맥락을 유지하는 책임은 전적으로 우리 쪽 서버에 있습니다.** 매번 "지금까지 나눈 대화 전체"를 메시지 배열에 통째로 담아서 다시 보내야, 모델이 그걸 보고 "아, 이런 대화를 하고 있었구나"라고 이어받는 겁니다.

즉 챗봇을 만든다는 건 결국 "**대화 기록을 잘 저장했다가, 매번 적절한 양만큼 잘라서 다시 보내는 것**"입니다.

## 1단계: 메시지 테이블

```php
$this->forge->addField([
    'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
    'nickname'   => ['type' => 'VARCHAR', 'constraint' => 32],
    'content'    => ['type' => 'TEXT'],
    'created_at' => ['type' => 'DATETIME', 'null' => true],
]);
$this->forge->addPrimaryKey('id');
$this->forge->createTable('chat_messages', true);
```

닉네임이 `'나'`면 사용자 메시지, `'AI봇'`이면 봇 메시지로 구분합니다. 굳이 `is_bot` 같은 boolean 컬럼을 따로 두지 않고 닉네임으로 구분해도 충분합니다 — 지나치게 정규화하기보다, 당장 필요한 만큼만 설계하는 게 실전에서는 더 유리할 때가 많습니다.

## 2단계: 사용자 메시지부터 저장

```php
$content = trim((string) ($this->request->getPost('content') ?? ''));

if ($content === '' || mb_strlen($content) > 500) {
    return $this->response->setStatusCode(400)->setJSON(['error' => '메시지를 확인해주세요.']);
}

$db  = db_connect();
$now = date('Y-m-d H:i:s');

$userId  = $db->table('chat_messages')->insert([
    'nickname'   => '나',
    'content'    => $content,
    'created_at' => $now,
], true);
```

`insert([...], true)`에서 두 번째 인자 `true`는 "방금 insert한 행의 ID를 반환하라"는 뜻입니다. 이 ID를 바로 화면에 돌려줘서, 프론트엔드가 자기가 보낸 메시지를 즉시 렌더링할 수 있게 합니다(AI 응답을 기다리는 동안 사용자 메시지만 먼저 보여주는 UX).

## 3단계: 최근 대화 내역을 Groq 메시지 형식으로 변환

```php
$history = $db->table('chat_messages')->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();

$systemPrompt = '당신은 친근하고 유쾌한 AI 어시스턴트입니다. 한국어로 자연스럽게 대화하세요. 2~3문장 이내로 답변하세요.';

$groqMessages = [['role' => 'system', 'content' => $systemPrompt]];
foreach (array_reverse($history) as $msg) {
    $groqMessages[] = [
        'role'    => $msg['nickname'] === 'AI봇' ? 'assistant' : 'user',
        'content' => $msg['content'],
    ];
}
```

최근 20개로 제한한 이유는 두 가지입니다. 대화가 길어질수록 매 요청마다 보내는 토큰 수가 늘어나서 ① 비용이 늘고 ② 모델의 컨텍스트 길이 한도에 걸릴 수 있습니다. "최근 N개만" 자르는 건 가장 단순한 해법이고, 대부분의 캐주얼한 챗봇에는 이 정도면 충분합니다. (더 긴 대화를 기억해야 한다면 오래된 대화를 요약해서 압축하는 방식도 있지만, 이 책에서는 다루지 않습니다.)

`DESC`로 최근 20개를 가져온 뒤 `array_reverse()`로 다시 시간 순서(오래된 것 → 최신 것)로 뒤집는 걸 놓치지 마세요. Groq는 메시지 배열을 **대화가 실제로 일어난 순서**로 기대합니다.

## 4단계: Groq 호출하고 봇 메시지 저장하기

```php
$response = \Config\Services::curlrequest()->post(
    'https://api.groq.com/openai/v1/chat/completions',
    [
        'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
        'body'    => json_encode([
            'model'            => config('PlaygroundConfig')->groqChatModel,
            'messages'         => $groqMessages,
            'max_tokens'       => 300,
            'temperature'      => 0.8,
            'reasoning_effort' => 'low',
        ]),
        'timeout' => 15,
    ]
);

$body       = json_decode($response->getBody(), true);
$botContent = trim($body['choices'][0]['message']['content'] ?? '');

$botNow = date('Y-m-d H:i:s');
$botId  = $db->table('chat_messages')->insert([
    'nickname'   => 'AI봇',
    'content'    => $botContent,
    'created_at' => $botNow,
], true);

return $this->response->setJSON([
    'success' => true,
    'user'    => ['id' => $userId, 'nickname' => '나', 'content' => $content, 'created_at' => $now],
    'bot'     => ['id' => $botId, 'nickname' => 'AI봇', 'content' => $botContent, 'created_at' => $botNow],
]);
```

`reasoning_effort => 'low'`는 실제로 겪은 버그 때문에 들어간 옵션입니다. 일부 추론(reasoning) 모델은 답변을 만들기 전에 "생각하는 과정"에 `max_tokens`를 먼저 소모하는데, 이게 전부 소진되면 **정작 사용자에게 보여줄 `content`가 빈 문자열로 돌아옵니다.** `reasoning_effort`를 낮게 설정하면 모델이 추론에 쓰는 토큰을 줄여서, 이 문제가 훨씬 덜 일어납니다. `temperature`는 응답의 창의성/무작위성을 조절하는 값으로, 챗봇처럼 자유로운 대화에는 0.8 정도의 높은 값을, 9장의 게시판 검색봇처럼 사실 기반 답변이 필요할 땐 0.3 정도의 낮은 값을 씁니다.

## 보너스: 최신 정보가 필요하면 웹 검색을 곁들이기

LLM은 학습 시점 이후의 정보(오늘 날씨, 최근 뉴스)는 알지 못합니다. 간단한 키워드 감지로 이런 질문을 구분해서, 검색 API(Serper) 결과를 시스템 프롬프트에 끼워 넣는 선택적 기능을 추가할 수 있습니다.

```php
private function needsSearch(string $content): bool
{
    if (trim(env('SERPER_API_KEY') ?? '') === '') {
        return false; // 검색 API 키가 없으면 그냥 끈다
    }

    foreach (['최신', '오늘', '현재', '지금', '뉴스', '날씨', '환율'] as $kw) {
        if (str_contains($content, $kw)) return true;
    }

    return (bool) preg_match('/20\d{2}년?/', $content); // "2026년" 같은 연도 패턴
}
```

검색 결과는 10분 캐시를 걸어둡니다 — 같은 질문이 짧은 시간에 반복되면 매번 검색 API를 호출하지 않고 캐시된 결과를 재사용합니다.

```php
$cacheKey = 'serper_' . md5($query);
$cached   = cache($cacheKey);
if ($cached !== null) return $cached;

// ... 검색 API 호출 ...

cache()->save($cacheKey, $result, 600); // 10분
```

이 기능은 완전히 선택 사항입니다. `SERPER_API_KEY`를 `.env`에 설정하지 않으면 `needsSearch()`가 항상 `false`를 반환해서, 그냥 평범한 챗봇으로 동작합니다.

## 아직 다루지 않은 것

- Groq 호출이 실패했을 때(쿼터 초과 등)의 처리는 단순한 `try/catch`뿐입니다. 11장에서 이 부분을 훨씬 견고하게 고칩니다.
- 답변에 마크다운이 섞여 나올 수 있다는 점은 12장에서 다룹니다.

## 체크리스트

- [ ] 메시지를 보내고, 몇 턴 뒤에 "아까 내가 뭐라고 했지?"라고 물으면 맥락을 기억하는가
- [ ] `chat_messages` 테이블에 사용자/봇 메시지가 번갈아 쌓이는가
- [ ] `SERPER_API_KEY` 없이도 챗봇 자체는 정상 동작하는가

## 전체 코드

[`examples/07-ai-chatbot/`](../examples/07-ai-chatbot/)에서 전체 원본을 받아볼 수 있습니다.

다음 장에서는 방금 만든 게시판에, AI로 스팸 글을 자동으로 걸러내는 기능을 붙여보겠습니다.
