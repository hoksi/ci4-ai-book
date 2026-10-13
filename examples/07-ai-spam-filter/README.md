# 7장 예제 — AI 스팸 필터

[7장 본문](../../chapters/07-ai-spam-filter.md)에서 다루는 코드의 실제 원본입니다. cikorea.net CI4 Playground에서 그대로 복사해왔습니다.

## 파일 설명

| 파일 | 원래 위치 | 역할 |
|---|---|---|
| `SpamChecker.php` | `app/Services/` | 규칙 → StopForumSpam → AI 3단계 스팸 판정 서비스 |
| `SpamKeywordModel.php` | `app/Models/` | 스팸 키워드 저장/조회, AI가 찾아낸 키워드 학습 |
| `SpamAdmin.php` | `app/Controllers/Examples/` | 키워드 관리 + `review` 상태 게시글 승인/차단 관리자 화면 |

## 내 프로젝트에 적용하는 법

1. 세 파일을 각각 `app/Services/`, `app/Models/`, `app/Controllers/`에 복사하고 네임스페이스를 실제 위치에 맞게 수정
2. 마이그레이션 3개 추가

   ```php
   // spam_keywords 테이블
   $this->forge->addField([
       'id'         => ['type' => 'INTEGER', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
       'keyword'    => ['type' => 'VARCHAR', 'constraint' => 200],
       'frequency'  => ['type' => 'INTEGER', 'default' => 1],
       'active'     => ['type' => 'TINYINT', 'default' => 1],
       'is_builtin' => ['type' => 'TINYINT', 'default' => 0],
       'created_at' => ['type' => 'DATETIME', 'null' => true],
       'updated_at' => ['type' => 'DATETIME', 'null' => true],
   ]);
   $this->forge->addPrimaryKey('id');
   $this->forge->addUniqueKey('keyword');
   $this->forge->createTable('spam_keywords');

   // posts 테이블에 컬럼 추가 (4장에서 만든 posts 테이블 기준)
   $this->forge->addColumn('posts', [
       'spam_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'approved', 'after' => 'author'],
   ]);
   ```

3. 내장 스팸 키워드(`SpamChecker::BUILTIN_KEYWORDS`)를 `spam_keywords` 테이블에 시딩 (`is_builtin = 1`로 저장하면 `SpamAdmin::delete()`에서 삭제가 자동으로 막힙니다)
4. 라우트 등록

   ```php
   $routes->get('spam-admin',                     'SpamAdmin::index');
   $routes->post('spam-admin/store',              'SpamAdmin::store');
   $routes->get('spam-admin/(:num)/toggle',       'SpamAdmin::toggle/$1');
   $routes->get('spam-admin/(:num)/delete',       'SpamAdmin::delete/$1');
   $routes->get('spam-admin/post/(:num)/approve', 'SpamAdmin::approvePost/$1');
   $routes->get('spam-admin/post/(:num)/spam',    'SpamAdmin::spamPost/$1');
   ```

5. 게시판 컨트롤러의 `store()`에서 `(new SpamChecker())->check($title, $content, $ip)` 호출 — 7장 본문의 "게시판에 연결하기" 참고
6. `.env`에 `GROQ_API_KEY` 설정 (없으면 AI 판정 단계는 자동으로 `approved`로 통과시키고 규칙+평판 점수만으로 판단)

관리자 화면 뷰(`spam-admin/index.php`)는 포함하지 않았습니다 — 키워드 목록 테이블과 `review` 게시글 목록을 보여주는 평범한 폼/테이블이라, 3장(뷰와 레이아웃)에서 배운 패턴으로 직접 만들어보는 걸 권장합니다.

## 원본 출처

cikorea.net CI4 Playground (비공개 저장소) — 2026-10-13 기준 코드.
