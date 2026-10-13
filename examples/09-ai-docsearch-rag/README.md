# 9장 예제 — AI 문서 검색봇(RAG)

[9장 본문](../../chapters/09-ai-docsearch-rag.md)에서 다루는 코드의 실제 원본입니다. cikorea.net CI4 Playground에서 그대로 복사해왔습니다.

## 파일 설명

| 파일 | 원래 위치 | 역할 |
|---|---|---|
| `BuildDocSearchIndex.php` | `app/Commands/` | `php spark docsearch:build` 커스텀 명령 — 매뉴얼 HTML을 스캔해 검색 인덱스(json) 생성 |
| `DocSearch.php` | `app/Controllers/Examples/` | 검색 인덱스에서 질문 관련 문서를 찾고 Groq에 질의하는 컨트롤러 |

## 내 프로젝트에 적용하는 법

1. `BuildDocSearchIndex.php`를 자신의 CI4 프로젝트 `app/Commands/`에 복사
2. `DocSearch.php`를 `app/Controllers/`(또는 원하는 하위 네임스페이스)에 복사하고, 맨 위 `namespace` 선언을 실제 위치에 맞게 수정
3. `app/Config/Routes.php`에 라우트 등록

   ```php
   $routes->get('docsearch',        'DocSearch::index');
   $routes->post('docsearch/ask',   'DocSearch::ask',   ['filter' => 'write-throttle:10,60']);
   $routes->post('docsearch/clear', 'DocSearch::clear');
   ```

   `write-throttle` 필터는 11장에서 다루는 요청 제한 필터입니다. 없다면 생략해도 동작은 합니다.

4. `doc_search_logs` 테이블 마이그레이션 추가 (4장에서 다룬 방식 그대로)

   ```php
   $this->forge->addField([
       'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
       'question'   => ['type' => 'VARCHAR', 'constraint' => 500],
       'answer'     => ['type' => 'TEXT'],
       'sources'    => ['type' => 'TEXT', 'null' => true],
       'created_at' => ['type' => 'DATETIME', 'null' => true],
   ]);
   $this->forge->addPrimaryKey('id');
   $this->forge->createTable('doc_search_logs', true);
   ```

5. `www/docs/Codeigniter4` 자리에 검색 대상으로 쓸 자신의 HTML 문서를 두고 `php spark docsearch:build` 실행 (경로가 다르면 `BuildDocSearchIndex.php`의 `$docsRoot`를 수정)
6. `.env`에 `GROQ_API_KEY` 설정

프런트엔드(질문 입력 폼, 마크다운 렌더링 포함)는 12장 예제 폴더에서 다룹니다.

## 원본 출처

cikorea.net CI4 Playground (비공개 저장소) — 2026-10-13 기준 코드.
