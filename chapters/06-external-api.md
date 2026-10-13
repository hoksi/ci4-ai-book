# 6장. 외부 API 연동 기초

여기서부터 2부입니다. 1부에서 만든 AI 게시판은 아직 평범한 CRUD 게시판일 뿐입니다. 이번 장부터는 외부 AI API(Groq)를 하나씩 붙여나갑니다. 그 전에, "CI4에서 외부 API를 호출하는 기본기"부터 다집니다 — AI API든 다른 API든 호출 방식 자체는 똑같습니다.

## CURLRequest: CI4 내장 HTTP 클라이언트

CI4는 외부 HTTP 요청을 위한 `CURLRequest` 클라이언트를 기본 제공합니다. `Config\Services`를 통해 가져옵니다.

```php
$client   = \Config\Services::curlrequest();
$response = $client->get('https://jsonplaceholder.typicode.com/posts/1');

$data = json_decode($response->getBody(), true);
```

`Services::curlrequest()`처럼 "서비스 로케이터"를 거쳐 객체를 가져오는 방식은 CI4 전반에서 반복되는 패턴입니다. `new CURLRequest()`를 직접 쓰지 않는 이유는, 테스트할 때 이 서비스를 가짜(mock) 객체로 바꿔치기할 수 있게 하기 위해서입니다.

## 쿼리 파라미터와 POST 바디

```php
// 쿼리 파라미터
$response = $client->get('https://jsonplaceholder.typicode.com/posts', [
    'query' => ['_limit' => 5],
]);

// JSON 바디로 POST
$response = $client->post('https://jsonplaceholder.typicode.com/posts', [
    'json' => [
        'title'  => '제목',
        'body'   => '본문',
        'userId' => 1,
    ],
]);
```

`json` 옵션을 쓰면 배열을 자동으로 JSON 문자열로 바꾸고 `Content-Type: application/json` 헤더도 알아서 붙여줍니다. 헤더를 직접 지정하고 싶다면 `headers`와 `body`를 따로 씁니다.

```php
$response = $client->post($url, [
    'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
    'body'    => json_encode(['model' => $model, 'messages' => $messages]),
    'timeout' => 20,
]);
```

Groq처럼 인증 헤더(`Authorization: Bearer ...`)가 필요한 API는 거의 항상 이 두 번째 방식을 씁니다.

## API 키는 절대 코드에 적지 않는다

1장에서 `.env`에 `GROQ_API_KEY`를 넣어뒀습니다. 코드에서는 `env()` 헬퍼로 꺼내 씁니다.

```php
$apiKey = trim((string) (env('GROQ_API_KEY') ?? ''));

if ($apiKey === '') {
    return $this->response->setStatusCode(400)->setJSON(['error' => 'Groq API 키가 필요합니다.']);
}
```

왜 코드에 직접 쓰면 안 되는지는 명확합니다 — 코드는 git에 커밋되고, git 저장소는(이 책의 9장, 13장에서처럼) 다른 사람과 공유되거나 공개될 수 있습니다. `.env`는 `.gitignore`에 포함되어 있어서 커밋되지 않습니다.

모델명처럼 "민감하진 않지만 환경마다 바뀔 수 있는 값"은 `Config` 클래스로 관리하면 더 깔끔합니다.

```php
<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class PlaygroundConfig extends BaseConfig
{
    // .env: playground.groqChatModel = <model id>
    public string $groqChatModel = 'openai/gpt-oss-20b'; // 기본값
}
```

`.env`에 `playground.groqChatModel = openai/gpt-oss-120b`라고 써두면, CI4가 자동으로 이 값을 읽어서 `$groqChatModel`을 덮어씁니다. 컨트롤러에서는 이렇게 꺼내 씁니다.

```php
config('PlaygroundConfig')->groqChatModel
```

모델을 바꾸고 싶을 때마다 코드를 수정하고 재배포할 필요 없이, `.env` 한 줄만 바꾸면 됩니다.

## Groq에 첫 요청 보내기

이제 실제로 Groq Chat Completions API를 호출해봅니다. OpenAI 호환 형식이라, OpenAI API를 써본 적이 있다면 익숙한 모양입니다.

```php
public function hello()
{
    $apiKey = trim((string) (env('GROQ_API_KEY') ?? ''));

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
                    ['role' => 'system', 'content' => '당신은 친절한 한국어 비서입니다.'],
                    ['role' => 'user', 'content' => '안녕하세요!'],
                ],
                'max_tokens' => 100,
            ]),
            'timeout' => 15,
        ]
    );

    $body   = json_decode($response->getBody(), true);
    $answer = $body['choices'][0]['message']['content'] ?? '(응답 없음)';

    return $this->response->setJSON(['answer' => $answer]);
}
```

`messages` 배열이 핵심입니다. `role: system`은 AI의 성격/지침을, `role: user`는 실제 사용자 메시지를 담습니다. `role: assistant`도 있는데, 이전 대화 내용을 포함시킬 때 씁니다 — 7장(AI 챗봇)에서 바로 이 세 번째 role을 활용해 대화 맥락을 이어가는 법을 다룹니다.

## 지금은 에러 처리를 자세히 다루지 않습니다

위 코드는 Groq가 정상 응답했을 때만 가정하고 있습니다. API 호출이 실패하거나(네트워크 오류), 쿼터를 초과하거나(429), 키가 잘못된 경우에 대한 처리가 전혀 없습니다. 이건 의도적입니다 — 이 장의 목표는 "일단 연결해서 응답 받아보기"까지이고, **제대로 된 에러 처리는 11장에서 깊게 다룹니다.** 지금 단계에서 너무 많은 걸 한 번에 처리하려 하면 핵심(외부 API 호출 자체)이 흐려집니다.

## 체크리스트

- [ ] `.env`의 `GROQ_API_KEY`가 올바르게 로드되는가 (`env('GROQ_API_KEY')`를 `var_dump` 해서 확인)
- [ ] `PlaygroundConfig`에 `.env`의 `playground.groqChatModel` 값이 실제로 반영되는가
- [ ] Groq에 "안녕하세요" 정도의 간단한 메시지를 보내고 응답을 받아봤는가

다음 장에서는 이 단발성 호출을 **이어지는 대화**로 바꿔서, 실제 챗봇 기능을 만들어보겠습니다.
