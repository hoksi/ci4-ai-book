# CodeIgniter4로 만드는 AI 웹 서비스

CodeIgniter4 기본기를 실전 AI 기능(챗봇, 스팸 필터, RAG 게시판 검색봇 등)을 직접 만들어보며 익히는 한국어 튜토리얼입니다.

공식 유저가이드([한글 번역](https://www.cikorea.net/docs/Codeigniter4))가 레퍼런스라면, 이 튜토리얼은 "그래서 이걸로 뭘 만드는가"에 초점을 맞춥니다. 대부분의 예제는 [cikorea.net CI4 Playground](https://cikorea2026.mycafe24.com/playground)에서 실제로 동작하는 코드를 기반으로 하며, 일부(9, 10장)는 이 책을 위해 새로 작성했습니다.

핵심 코드는 각 챕터 본문에 전부 인용되어 있습니다. Playground 저장소 자체는 비공개이지만, 챕터에서 다루는 파일의 전체 원본은 [`examples/`](examples/) 폴더에 별도로 공개해뒀습니다.

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
| [6장. 외부 API 연동 기초](chapters/06-external-api.md) | Groq에 첫 요청 보내기, temperature/reasoning_effort | CURLRequest, Services, Config |
| [7장. AI 스팸 필터 만들기](chapters/07-ai-spam-filter.md) | 게시글 스팸 자동 판별 | Filters, 이벤트 훅 |
| [8장. AI 게시판 검색봇(RAG) 만들기](chapters/08-ai-board-search.md) | 게시글 기반 Q&A 봇 | 키워드 검색/점수 매기기, 프롬프트 설계 |
| [9장. AI 챗봇 만들기](chapters/09-ai-chatbot.md) | 대화 컨텍스트, DB 저장 (게시판과 독립) | 상태 관리, JSON 응답 |
| [10장. 실시간으로 AI 응답 보여주기](chapters/10-sse-streaming.md) | SSE 스트리밍 | SSE |

### 3부 — 실전 다듬기

| 챕터 | 내용 | CI4 개념 |
|---|---|---|
| [11장. AI 호출 안전하게 다루기](chapters/11-rate-limit-error.md) | 쿼터 초과(429) 처리, 요청 제한 | Throttler, 에러 핸들링 |
| [12장. 마크다운 응답 안전하게 렌더링하기](chapters/12-markdown-xss.md) | XSS 방어 | esc(), 출력 이스케이프 |
| [13장. 배포하기](chapters/13-deployment.md) | php-cli가 막힌 공유 호스팅 배포 | Git 배포, SFTP, SQLite 마이그레이션 우회 |
| [14장. 마무리](chapters/14-wrapup.md) | 더 만들어볼 수 있는 아이디어 | — |

### 부록

| 부록 | 내용 |
|---|---|
| [부록 A. Windows + WSL 개발 환경 만들기](chapters/appendix-a-windows-wsl.md) | WSL2 설치부터 PHP/Composer, VS Code 연동까지 |

## 진행 상태

1\~14장 전체 집필 완료. 부록 A(Windows + WSL) 추가.

## 라이선스

이 책은 자유롭게 퍼지는 걸 목표로 합니다.

- **책 본문**(README, `chapters/` 설명 텍스트): [CC BY-NC-SA 4.0](LICENSE) — 출처만 표시하면 자유롭게 공유·번역·변형 가능, 비영리 조건, 2차 저작물도 동일 라이선스로 공유
- **코드**(`chapters/` 안 코드 스니펫, `examples/` 전체): [MIT License](examples/LICENSE) — 제약 없이 자신의 프로젝트에 바로 가져다 써도 됩니다
