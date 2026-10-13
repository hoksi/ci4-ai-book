# 9장 예제 — AI 챗봇

[9장 본문](../../chapters/09-ai-chatbot.md)에서 다루는 코드의 실제 원본입니다. cikorea.net CI4 Playground에서 그대로 복사해왔습니다.

## 파일 설명

| 파일 | 원래 위치 | 역할 |
|---|---|---|
| `Chat.php` | `app/Controllers/Examples/` | 대화 내역 저장/조회, 웹 검색 보강, Groq 호출까지 담당하는 챗봇 컨트롤러 |

## 내 프로젝트에 적용하는 법

1. `Chat.php`를 자신의 CI4 프로젝트 `app/Controllers/`에 복사하고 `namespace`를 실제 위치에 맞게 수정
2. 라우트 등록

   ```php
   $routes->get('chat',        'Chat::index');
   $routes->post('chat/send',  'Chat::send', ['filter' => 'write-throttle:10,60']);
   $routes->post('chat/clear', 'Chat::clear');
   ```

3. `chat_messages` 테이블 마이그레이션 추가

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

4. `.env`에 `GROQ_API_KEY` 설정 (웹 검색 보강 기능을 쓰려면 `SERPER_API_KEY`도 추가 — 없으면 자동으로 비활성화됨)
5. `config('PlaygroundConfig')->groqChatModel` 호출 부분은 6장에서 만든 `PlaygroundConfig` 클래스가 필요합니다. 없다면 모델명을 직접 문자열로 바꿔 넣어도 됩니다.

프런트엔드(채팅 UI)는 별도로 제공하지 않습니다 — `send()`가 반환하는 JSON(`{user, bot}`)을 받아 말풍선을 렌더링하는 JS는 8장 예제의 프런트엔드 패턴을 참고하세요.

## 원본 출처

cikorea.net CI4 Playground (비공개 저장소) — 2026-10-13 기준 코드.
