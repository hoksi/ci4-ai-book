# CodeIgniter4로 만드는 AI 웹 서비스

CodeIgniter4 기본기를 실전 AI 기능(챗봇, 스팸 필터, RAG 문서 검색봇 등)을 직접 만들어보며 익히는 한국어 튜토리얼입니다.

공식 유저가이드([한글 번역](https://github.com/hoksi/codeigniter4-docs-ko))가 레퍼런스라면, 이 튜토리얼은 "그래서 이걸로 뭘 만드는가"에 초점을 맞춥니다. 모든 예제는 [cikorea.net CI4 Playground](https://cikorea2026.mycafe24.com/playground)에서 실제로 동작하는 코드를 기반으로 합니다.

## 대상 독자

- CI4 문법은 대충 알지만 실전 프로젝트를 어떻게 엮는지 감이 안 잡히는 분
- AI API(Groq 등)를 웹 서비스에 붙여보고 싶은 분

## 목차

### 1부 — 기초 다지기

| 챕터 | 내용 | CI4 개념 |
|---|---|---|
| [1장. 왜 CI4 + AI인가, 개발 환경 세팅](chapters/01-intro-setup.md) | Composer, .env, Groq API 키 발급 | 설치/설정 |
| [2장. 라우팅과 컨트롤러](chapters/02-routing-controller.md) | "AI 게시판" 뼈대 만들기 | Routes, Controller |
| [3장. 뷰와 레이아웃](chapters/03-views-layout.md) | 템플릿 구성 | View, extend/section |
| [4장. 모델과 DB](chapters/04-models-db.md) | 마이그레이션/시더 | Migration, Model, Query Builder |
| [5장. 폼과 유효성 검사](chapters/05-validation.md) | 입력값 검증 | Validation |

### 2부 — AI 기능 하나씩 구현하기

| 챕터 | 내용 | CI4 개념 |
|---|---|---|
| [6장. 외부 API 연동 기초](chapters/06-external-api.md) | Groq에 첫 요청 보내기 | CURLRequest, Services, Config |
| [7장. AI 챗봇 만들기](chapters/07-ai-chatbot.md) | 대화 컨텍스트, DB 저장 | 상태 관리, JSON 응답 |
| [8장. AI 스팸 필터 만들기](chapters/08-ai-spam-filter.md) | 게시글 스팸 자동 판별 | Filters, 이벤트 훅 |
| [9장. AI 문서 검색봇(RAG) 만들기](chapters/09-ai-docsearch-rag.md) | 매뉴얼 기반 Q&A 봇 | 커스텀 spark 커맨드, 검색/인덱싱, 프롬프트 설계 |
| [10장. 실시간으로 AI 응답 보여주기](chapters/10-sse-streaming.md) | SSE 스트리밍 | SSE |

### 3부 — 실전 다듬기

| 챕터 | 내용 | CI4 개념 |
|---|---|---|
| [11장. AI 호출 안전하게 다루기](chapters/11-rate-limit-error.md) | 쿼터 초과(429) 처리, 요청 제한 | Throttler, 에러 핸들링 |
| [12장. 마크다운 응답 안전하게 렌더링하기](chapters/12-markdown-xss.md) | XSS 방어 | esc(), 출력 이스케이프 |
| [13장. 배포하기](chapters/13-deployment.md) | php-cli가 막힌 공유 호스팅 배포 | Git 배포, SFTP, SQLite 마이그레이션 우회 |
| [14장. 마무리](chapters/14-wrapup.md) | 더 만들어볼 수 있는 아이디어 | — |

## 진행 상태

전체 목차 확정. 1~5장(기초 다지기), 9장(RAG), 11장(AI 호출 에러 처리), 12장(마크다운 안전 렌더링), 13장(배포하기) 집필 완료. 남은 건 6~8장, 10장, 14장.

## 라이선스

추후 결정 (당분간 전체 권리 보유).
