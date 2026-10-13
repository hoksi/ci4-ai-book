# 10장. 실시간으로 AI 응답 보여주기

## 이번 장에서 만드는 것

7장의 챗봇은 Groq가 답변을 다 만들 때까지 기다렸다가 한 번에 통째로 보여줬습니다. ChatGPT 같은 서비스는 답이 완성되기 전부터 한 글자씩 "타이핑되는" 것처럼 보여줍니다. 이번 장에서는 Groq의 스트리밍 응답을 받아서, 우리 서버를 거쳐 브라우저에 실시간으로 중계하는 법을 다룹니다.

## SSE(Server-Sent Events)란

실시간으로 서버에서 클라이언트로 데이터를 보내는 방법은 여러 가지가 있지만, **서버 → 클라이언트 단방향**이면 충분한 경우(채팅 응답 스트리밍, 알림, 진행률 표시 등)에는 WebSocket보다 **SSE**가 더 간단합니다.

- 평범한 HTTP 연결 위에서 동작합니다 (별도 프로토콜 업그레이드 불필요)
- 브라우저 내장 `EventSource` API가 **연결이 끊기면 자동으로 재연결**해줍니다
- 방화벽/프록시 호환성이 WebSocket보다 좋습니다

단점은 클라이언트 → 서버 방향 통신은 못한다는 것뿐입니다(그건 그냥 별도의 일반 요청으로 보내면 됩니다).

## CI4에서 SSE 엔드포인트의 기본 틀

SSE 응답을 만들 때 꼭 필요한 처리들이 있습니다.

```php
public function stream(): void
{
    // PHP/CI4가 쌓아둔 출력 버퍼를 전부 비운다 — 안 그러면 flush()가 안 먹힌다
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    @set_time_limit(0);       // 스크립트가 중간에 타임아웃으로 끊기지 않게
    ob_implicit_flush(true);  // echo할 때마다 즉시 출력

    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // nginx가 응답을 버퍼링하지 않도록
    header('Connection: keep-alive');

    echo ": ping\n\n"; // 연결 확인용 더미 이벤트
    flush();
}
```

`X-Accel-Buffering: no`를 빼먹으면, nginx 같은 리버스 프록시가 응답을 전부 모았다가 한 번에 내려보내서 "스트리밍"이 전혀 안 되는 것처럼 보일 수 있습니다. 실전에서 자주 걸리는 함정입니다.

이벤트는 `event:`/`data:`/`id:` 줄로 구성된 특정 텍스트 포맷으로 보냅니다.

```php
private function sendEvent(string $event, array $data, string|int $id = ''): void
{
    if ($id !== '') {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n"; // 반드시 빈 줄로 끝나야 함
}
```

클라이언트가 연결을 끊으면 `connection_aborted()`로 감지해서 루프를 멈출 수 있습니다 — 안 그러면 서버가 이미 아무도 안 듣는 스트림에 계속 데이터를 흘려보내게 됩니다.

```php
for ($tick = 0; $tick < $maxTicks; $tick++) {
    if (connection_aborted()) {
        break;
    }
    // ... 이벤트 전송 ...
    flush();
    sleep(2);
}
```

## Groq의 스트리밍 응답 받기

여기서부터가 이 장의 핵심입니다. Groq(OpenAI 호환 API)는 요청에 `"stream": true`를 추가하면, 응답을 한 번에 주지 않고 **토큰이 생성되는 대로 조각조각** 흘려보내줍니다.

문제는 6~9장에서 써온 CI4의 `CURLRequest`가 **응답을 전부 받은 뒤에야 반환하는 구조**라는 점입니다. 토큰이 도착하는 즉시 처리하려면, 더 저수준의 `curl_setopt(CURLOPT_WRITEFUNCTION, ...)`을 직접 써야 합니다. (8장의 `SpamChecker`에서 StopForumSpam을 호출할 때도 비슷하게 저수준 curl을 썼던 걸 기억해보세요 — CI4의 고수준 래퍼가 모든 상황에 맞는 건 아닙니다.)

```php
public function stream(): void
{
    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);
    ob_implicit_flush(true);

    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $question = (string) $this->request->getGet('q');
    $apiKey   = env('GROQ_API_KEY');

    $payload = json_encode([
        'model'    => config('PlaygroundConfig')->groqChatModel,
        'messages' => [['role' => 'user', 'content' => $question]],
        'stream'   => true, // ← 이 옵션이 핵심
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => false, // 응답을 모으지 않는다
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
            foreach (explode("\n", trim($chunk)) as $line) {
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    echo "event: done\ndata: {}\n\n";
                    flush();
                    continue;
                }

                $delta = json_decode($json, true)['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    echo "event: token\n";
                    echo 'data: ' . json_encode(['text' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                }
            }

            return strlen($chunk); // CURLOPT_WRITEFUNCTION은 처리한 바이트 수를 반환해야 한다
        },
    ]);

    curl_exec($ch);
    curl_close($ch);
}
```

`CURLOPT_WRITEFUNCTION`에 넘긴 콜백은 Groq에서 데이터 조각(chunk)이 도착할 때마다 즉시 호출됩니다. Groq의 스트리밍 응답은 `data: {...}` 줄이 계속 이어지다가 마지막에 `data: [DONE]`으로 끝나는 형식입니다. 각 조각에서 `choices[0].delta.content`를 꺼내면, 그게 바로 "방금 막 생성된 다음 글자(들)"입니다. 이걸 우리 서버의 SSE 이벤트로 즉시 다시 포장해서 내보냅니다.

## 프런트엔드: EventSource로 받아서 한 글자씩 붙이기

```js
const answerEl = document.getElementById('answer');
let answer = '';

const es = new EventSource('/chat/stream?q=' + encodeURIComponent(question));

es.addEventListener('token', (e) => {
    const { text } = JSON.parse(e.data);
    answer += text;
    answerEl.textContent = answer; // 완성되면 12장의 renderMarkdown()으로 교체해도 좋다
});

es.addEventListener('done', () => {
    es.close();
    // answerEl.innerHTML = renderMarkdown(answer); // 최종적으로 마크다운 렌더링
});
```

`EventSource`는 몇 가지를 알아서 처리해줍니다 — 연결이 중간에 끊기면 자동으로 재연결을 시도하고, `event:`로 지정한 이벤트 이름별로 다른 리스너를 등록할 수 있습니다. `done` 이벤트를 받으면 `es.close()`로 연결을 정리하는 걸 잊지 마세요. 안 닫으면 브라우저가 계속 재연결을 시도합니다.

## 체크리스트

- [ ] 응답이 한 번에 뚝 떨어지지 않고 글자 단위로 순차적으로 보이는가
- [ ] 리버스 프록시(nginx 등) 뒤에 배포했을 때도 스트리밍이 끊기지 않는가 (`X-Accel-Buffering: no` 확인)
- [ ] 탭을 닫거나 새로고침했을 때 서버 쪽에서 더 이상 불필요한 작업을 하지 않는가 (`connection_aborted()`)

## 한계

- 이번 장의 스트리밍 프록시는 11장에서 다룬 에러 처리(429 등)를 아직 반영하지 않았습니다. 스트림 도중에 Groq가 에러를 반환하면 `event: error` 같은 별도 이벤트로 클라이언트에 알려주는 처리를 추가하는 게 실전에서는 필요합니다.
- Nginx/Apache 설정에 따라 `X-Accel-Buffering` 외에도 프록시 자체의 버퍼링 설정을 꺼야 할 수 있습니다. 배포 환경마다 직접 확인이 필요합니다.

## 전체 코드

이번 장의 기본 SSE 메커니즘(출력 버퍼 처리, 헤더, 이벤트 포맷)은 [`examples/10-sse-streaming/`](../examples/10-sse-streaming/)의 `Sse.php` 원본을 참고하세요. Groq 스트리밍 프록시 코드는 이 장에서 새로 구성한 예제로, 위 코드 전체가 적용에 필요한 전부입니다.

2부와 3부를 모두 마쳤습니다. 마지막 장에서 지금까지 만든 것을 정리하고, 더 시도해볼 수 있는 아이디어를 짚어보겠습니다.
