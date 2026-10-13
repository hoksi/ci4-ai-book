# 11장. AI 호출 안전하게 다루기

## 이번 장에서 다루는 문제

Groq, OpenAI 같은 AI API의 무료 티어(혹은 저가 플랜)에는 분당/일일 요청 제한이 있습니다. 서비스를 운영하다 보면 언젠가 반드시 이 한도에 부딪힙니다. 그 순간 사용자에게 뭐가 보이는지 미리 확인해두지 않으면, 정작 트래픽이 몰리는 제일 중요한 순간에 암호 같은 에러 메시지만 띄우게 됩니다.

## CI4의 CURLRequest, 생각보다 불친절하다

CI4의 `CURLRequest`는 기본값으로 `CURLOPT_FAILONERROR`를 켭니다. 이 옵션이 켜지면 **HTTP 응답 코드가 400 이상일 때 curl이 응답 바디를 통째로 버려버립니다.** 직접 재현해보겠습니다. 429를 반환하는 목업 서버에 순수 curl로 요청을 보내면:

```php
$ch = curl_init("http://127.0.0.1:8924/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FAILONERROR, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
$output = curl_exec($ch);

var_dump($output);                              // bool(false)
var_dump(curl_errno($ch));                       // int(22)
var_dump(curl_error($ch));                       // "The requested URL returned error: 429"
var_dump(curl_getinfo($ch, CURLINFO_HTTP_CODE));  // int(429)
```

`$output`이 `false`입니다. Groq가 실제로 보내준 `{"error": {"message": "Rate limit reached...", "type": "rate_limit_exceeded"}}` 같은 유용한 본문은 **아예 전달받지 못합니다.** CI4는 이 상황을 `HTTPException`으로 변환해서 던지는데, 우리 컨트롤러에서 이렇게 잡고 있었다면:

```php
try {
    $response = \Config\Services::curlrequest()->post($url, [...]);
    // ...
} catch (\Throwable $e) {
    return $this->response->setStatusCode(500)
        ->setJSON(['error' => 'Groq API 오류: ' . $e->getMessage()]);
}
```

사용자는 결국 이런 메시지를 받습니다.

```json
{"error": "Groq API 오류: 22 : The requested URL returned error: 429"}
```

쿼터 초과인지, API 키가 잘못됐는지, 네트워크가 끊겼는지 전혀 구분이 안 되는 메시지입니다.

## 고치기: http_errors를 끄고 상태 코드로 직접 분기한다

`http_errors => false` 옵션 하나로 이 문제를 해결할 수 있습니다. 이 옵션을 끄면 curl이 더 이상 바디를 버리지 않고, 우리가 상태 코드를 직접 보고 처리할 수 있게 됩니다.

```php
$response = \Config\Services::curlrequest()->post(
    'https://api.groq.com/openai/v1/chat/completions',
    [
        'headers'     => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
        'body'        => json_encode([...]),
        'http_errors' => false,   // ← 이거 하나 추가
        'timeout'     => 20,
    ]
);

$status = $response->getStatusCode();
$body   = json_decode($response->getBody(), true);

if ($status === 429) {
    return $this->response->setStatusCode(503)->setJSON([
        'error' => 'AI 사용량이 초과되었습니다. 잠시 후 다시 시도해주세요.',
    ]);
}

if ($status >= 400) {
    $detail = $body['error']['message'] ?? '알 수 없는 오류';
    return $this->response->setStatusCode(502)->setJSON([
        'error' => 'AI 서비스 오류: ' . $detail,
    ]);
}

$answer = trim($body['choices'][0]['message']['content'] ?? '');
```

이제 429는 "사용량 초과"라는 명확한 메시지로, 그 외 4xx/5xx는 Groq가 실제로 보내준 에러 메시지(`$body['error']['message']`)를 그대로 보여줄 수 있습니다. 디버깅할 때도, 사용자를 안심시킬 때도 훨씬 낫습니다.

> **주의**: 이 수정은 AI를 호출하는 모든 곳에 똑같이 적용해야 합니다. 챗봇, AI 게임 반응, 스팸 판별, 문서 검색봇처럼 Groq를 호출하는 지점이 여러 곳이라면, 한 군데만 고치고 나머지를 그대로 두면 "반쪽짜리 수정"이 됩니다. 공통 헬퍼 함수나 서비스 클래스로 Groq 호출 부분을 한 곳에 모아두면 이런 누락을 막을 수 있습니다.

## 애초에 한도에 덜 부딪히게: 요청 자체를 제한하기

에러를 예쁘게 보여주는 것과 별개로, **한 사용자가 AI 기능을 과도하게 연타하지 못하게 막는 것**도 중요합니다. CI4에는 토큰 버킷 알고리즘 기반의 `Throttler` 서비스가 내장되어 있고, 필터로 라우트에 붙일 수 있습니다.

```php
$routes->post('docsearch/ask', 'Examples\DocSearch::ask', ['filter' => 'write-throttle:10,60']);
```

`10,60`은 "60초에 10번까지"라는 뜻입니다. 한 사람이 짧은 시간에 너무 많은 질문을 쏟아부어서 하루 쿼터를 혼자 다 써버리는 상황을 막아줍니다. AI처럼 호출 비용이 있는 기능에는 거의 항상 이런 필터를 같이 달아두는 게 좋습니다.

## 한계

- 이번 장은 429(Too Many Requests)만 집중적으로 다뤘습니다. 네트워크 타임아웃, Groq 서버 자체의 일시적 장애(5xx) 등도 비슷한 패턴으로 분기할 수 있지만, 재시도(retry with exponential backoff)까지 가면 더 견고해집니다. 이 책에서는 다루지 않지만, 트래픽이 늘어나면 고려해볼 만한 다음 단계입니다.
- `http_errors => false`로 바꾸면 실패 시에도 예외가 던져지지 않으므로, `try/catch`에 의존하던 기존 에러 처리 흐름을 상태 코드 분기 쪽으로 옮겨야 합니다. 이 둘을 섞어 쓰면 오히려 에러를 놓치기 쉬우니, 한 번 바꿀 때 호출 지점 전체를 일관되게 정리하세요.
