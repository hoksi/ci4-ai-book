# 8장 예제 — AI 게시판 검색봇(RAG)

[8장 본문](../../chapters/08-ai-board-search.md)에서 다루는 코드의 전체 원본입니다.

## 파일 설명

| 파일 | 원래 위치 | 역할 |
|---|---|---|
| `BoardSearch.php` | `app/Controllers/Examples/` | 승인된 게시글을 대상으로 질문 관련 글을 찾고 Groq에 질의하는 컨트롤러 |

## 내 프로젝트에 적용하는 법

1. `BoardSearch.php`를 자신의 CI4 프로젝트 `app/Controllers/`(또는 원하는 하위 네임스페이스)에 복사하고, 맨 위 `namespace` 선언을 실제 위치에 맞게 수정
2. `app/Config/Routes.php`에 라우트 등록

   ```php
   $routes->get('board-search',        'BoardSearch::index');
   $routes->post('board-search/ask',   'BoardSearch::ask',   ['filter' => 'write-throttle:10,60']);
   $routes->post('board-search/clear', 'BoardSearch::clear');
   ```

   `write-throttle` 필터는 11장에서 다루는 요청 제한 필터입니다. 없다면 생략해도 동작은 합니다.

3. `board_search_logs` 테이블 마이그레이션 추가 (4장에서 다룬 방식 그대로)

   ```php
   $this->forge->addField([
       'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
       'question'   => ['type' => 'VARCHAR', 'constraint' => 500],
       'answer'     => ['type' => 'TEXT'],
       'sources'    => ['type' => 'TEXT', 'null' => true],
       'created_at' => ['type' => 'DATETIME', 'null' => true],
   ]);
   $this->forge->addPrimaryKey('id');
   $this->forge->createTable('board_search_logs', true);
   ```

4. 4장의 `PostModel`, 7장의 `spam_status` 컬럼이 이미 있어야 합니다 — 이 코드는 그 위에 바로 올라갑니다
5. `.env`에 `GROQ_API_KEY` 설정

프런트엔드(질문 입력 폼, 마크다운 렌더링 포함)는 12장 예제 폴더의 패턴을 그대로 재사용하면 됩니다.

## 원본 출처

이 책을 위해 새로 작성 — cikorea.net Playground에는 이 기능이 아직 없습니다 (Playground에는 매뉴얼 문서 검색봇 버전이 있습니다).
