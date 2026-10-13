# 10장 예제 — 실시간으로 AI 응답 보여주기 (SSE)

[10장 본문](../../chapters/10-sse-streaming.md)에서 다루는 코드입니다.

## 파일 설명

| 파일 | 원래 위치 | 역할 |
|---|---|---|
| `Sse.php` | `app/Controllers/Examples/` | cikorea.net Playground의 실제 SSE 데모 원본 — 출력 버퍼 처리, 헤더 설정, `event:`/`data:`/`id:` 포맷으로 이벤트를 보내는 기본 틀을 보여줌 (시스템 상태/큐 현황/알림을 주기적으로 푸시) |

## Groq 스트리밍 프록시 코드

`Sse.php`는 일반적인 SSE 데모이고, 질문에 답하며 AI 토큰을 실시간으로 스트리밍하는 코드는 아직 cikorea.net Playground에 없습니다 — 10장 본문에 나온 `CURLOPT_WRITEFUNCTION` 기반 Groq 스트리밍 프록시 전체 코드가 곧 이 기능의 유일한 소스입니다. 본문의 코드 블록을 그대로 복사해서 쓰면 됩니다.

## 내 프로젝트에 적용하는 법

1. `Sse.php`를 참고해 스트리밍 엔드포인트를 작성 (본문의 `stream()` 메서드 전체 참고)
2. 라우트 등록

   ```php
   $routes->get('chat/stream', 'Chat::stream');
   ```

3. `.env`에 `GROQ_API_KEY` 설정
4. 프런트엔드에서 `EventSource`로 `chat/stream?q=...` 구독 (본문의 JS 코드 참고)
5. 리버스 프록시(nginx 등) 뒤에 배포한다면 버퍼링 관련 설정을 꼭 확인하세요 — 10장 본문의 "한계" 참고

## 원본 출처

`Sse.php`: cikorea.net CI4 Playground (비공개 저장소) — 2026-10-13 기준 코드.
Groq 스트리밍 프록시: 이 책을 위해 새로 작성.
