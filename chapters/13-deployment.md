# 13장. 배포하기

## 이번 장에서 다루는 상황

지금까지 만든 기능들은 로컬에서는 잘 돌아갑니다. 문제는 실제로 서비스할 서버가 **흔히 상상하는 VPS/클라우드 서버가 아닐 때**입니다. 국내 저가형 공유 호스팅(cafe24 같은)에서는 다음과 같은 제약이 흔합니다.

- SSH 접속은 되지만 **`php` 명령어 자체가 막혀 있다** (`php spark migrate` 같은 걸 서버에서 실행할 수 없음)
- `composer`도 당연히 못 쓴다 (composer 자체가 PHP CLI로 동작하는 도구라서)
- DB가 MySQL이 아니라 **SQLite 파일 하나**인 경우도 많다

"코드는 Git으로 관리하고 있는데, 그럼 배포는 대체 어떻게 하지?" — 이번 장은 이 질문에 대한 답입니다.

## 1단계: 뭐가 되고 뭐가 안 되는지 먼저 확인한다

짐작으로 움직이지 말고, SSH로 직접 들어가서 하나씩 확인합니다.

```bash
ssh your_id@your-account.cafe24.com
cd ~
git --version       # 2.37.0 — 된다
php -v               # "php: command not found" — 안 된다
ls -la               # .git 디렉터리가 이미 있다?!
```

여기서 중요한 발견이 하나 있을 수 있습니다 — **서버의 홈 디렉터리 자체가 이미 Git 작업 복사본인 경우**입니다. 처음 배포할 때 `git clone`으로 올려뒀다면, `.git`, `.git-credentials`, `.gitconfig`가 그대로 남아 있습니다. 즉 `php`는 막혀 있어도 **`git`은 멀쩡히 쓸 수 있다**는 뜻입니다. 이건 생각보다 큰 차이를 만듭니다.

```bash
git remote -v
git branch -vv
git status
```

로 현재 추적 중인 브랜치와 원격 저장소를 확인해둡니다.

## 2단계: 코드 배포는 git pull로 끝낸다

`vendor/`(composer 의존성)를 `.gitignore`에서 제외하지 않았다면, 그리고 이번 배포에 **새로운 composer 패키지를 추가하지 않았다면**, 코드 배포는 정말 `git pull` 한 줄로 끝납니다.

```bash
git fetch origin
git pull --ff-only origin main
```

`--ff-only`를 꼭 붙이세요. 서버에서 누군가 실수로 파일을 직접 고쳐놨다거나 untracked 파일이 쌓여 있어도, fast-forward가 안 되면 그냥 실패하고 멈추기 때문에 안전합니다. 병합 충돌이 운영 서버 한복판에서 일어나는 것보다 훨씬 낫습니다.

새 composer 패키지가 추가된 배포라면 얘기가 달라집니다 — `composer install`을 서버에서 못 돌리니, **로컬에서 `vendor/`를 통째로 압축해서 SFTP로 올리는** 수밖에 없습니다. 이건 번거롭고 느리므로, 가능하면 새 기능을 설계할 때 "서버에 이미 있는 PHP 확장(DOMDocument, cURL 등)만으로 되는가"를 한 번 더 고민해볼 가치가 있습니다. (9장의 게시판 검색봇도 새 composer 패키지 없이 기존 Model과 cURL만으로 구현한 것도 같은 이유입니다.)

## 3단계: DB 마이그레이션은 진짜 문제다

코드야 git pull로 해결됐는데, **스키마가 바뀐 마이그레이션은 어떻게 적용할까요?** `php spark migrate`를 서버에서 실행할 수 없다는 게 바로 이 순간 발목을 잡습니다.

DB가 MySQL이었다면 phpMyAdmin 같은 웹 관리 도구로 수동 SQL을 실행하면 그만입니다. 하지만 **SQLite는 그냥 파일 하나**라, 웹 관리 도구가 따로 없는 경우가 많습니다. 이럴 때 쓸 수 있는 방법은 하나입니다 — **그 파일을 잠깐 로컬로 가져와서, 이미 `php`가 멀쩡히 동작하는 로컬 환경에서 마이그레이션을 수행한 뒤 다시 올리는 것**입니다.

흐름은 이렇습니다.

1. 서버의 운영 DB 파일을 `.bak.<타임스탬프>`로 먼저 복사해둔다 (순수 `cp`, php 아님 — 이건 되니까)
2. SFTP로 운영 DB 파일을 로컬로 내려받는다
3. 로컬 프로젝트의 `writable/database.db` 자리에 잠깐 넣고 `php spark migrate --all` 실행
4. 마이그레이션된 파일을 다시 SFTP로 서버에 업로드한다
5. 로컬 개발용 DB는 원래 상태로 복원해둔다 (실수로 운영 데이터가 로컬 dev DB를 덮어쓰지 않도록)

```python
import datetime, shutil, subprocess, getpass
from pathlib import Path
import paramiko

HOST, USER = "your-account.cafe24.com", "your_id"
REMOTE_DB  = "writable/database.db"
LOCAL_REPO = Path("/path/to/your/local/checkout")
LOCAL_DB   = LOCAL_REPO / "writable" / "database.db"

password = getpass.getpass(f"{USER}@{HOST} 비밀번호: ")  # 비밀번호는 저장하지 않는다
ts = datetime.datetime.now().strftime("%Y%m%d%H%M%S")

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=password)

# 1) 원격 백업
client.exec_command(f"cp {REMOTE_DB} {REMOTE_DB}.bak.{ts}")

# 2) 다운로드
sftp = client.open_sftp()
downloaded = Path(f"/tmp/prod_db.{ts}.sqlite")
sftp.get(REMOTE_DB, str(downloaded))

# 3) 로컬에서 마이그레이션 (로컬 dev DB는 임시 백업 후 복원)
dev_backup = Path(f"/tmp/dev_db.{ts}.sqlite")
shutil.copy2(LOCAL_DB, dev_backup)
shutil.copy2(downloaded, LOCAL_DB)
subprocess.run(["php", "spark", "migrate", "--all"], cwd=LOCAL_REPO, check=True)
migrated = Path(f"/tmp/migrated_db.{ts}.sqlite")
shutil.copy2(LOCAL_DB, migrated)
shutil.copy2(dev_backup, LOCAL_DB)  # 로컬 dev DB 원상복구

# 4) 업로드
sftp.put(str(migrated), REMOTE_DB)
sftp.close()
client.close()
```

paramiko를 쓴 이유는 간단합니다 — 많은 공유 호스팅 환경에서는 `sshpass`가 설치되어 있지 않고, 매번 비밀번호를 손으로 치기도 번거롭습니다. Python으로 SSH/SFTP를 직접 다루면 이 전체 흐름을 스크립트 하나로 묶을 수 있습니다.

## 왜 "서버에서 웹으로 명령을 실행하는 엔드포인트"를 만들면 안 되는가

여기서 "매번 이렇게 로컬을 거치는 게 번거로우니, 토큰으로 보호된 `/ops/migrate` 같은 웹 엔드포인트를 하나 만들어서 브라우저로 호출하면 되지 않을까?"라는 생각이 들 수 있습니다. CI4는 실제로 `command('migrate --all')`이라는 헬퍼를 제공해서, 일반 웹 요청 핸들러 안에서도 spark 명령을 실행할 수 있게 해줍니다.

**이 방식은 권장하지 않습니다.** 아무리 토큰으로 막아놔도, 결국 "그 토큰만 알면 서버에서 임의의 명령을 실행할 수 있는 통로"를 공개 인터넷에 열어두는 것과 같습니다. 토큰은 접속 로그, 브라우저 히스토리, referrer 헤더 등으로 의외로 쉽게 샙니다. 운영 DB를 건드리는 작업은 **항상 사람이 직접, 의도를 가지고, 한 번에 하나씩** 실행하는 편이 안전합니다. 번거로움은 보안과 맞바꾼 비용이라고 생각하는 게 맞습니다.

## 체크리스트

배포 전에 다음을 확인하세요.

- [ ] 이번 배포에 새 composer 패키지가 포함되는가? → 포함된다면 `vendor/` 동기화 계획을 별도로 세운다
- [ ] 이번 배포에 새 마이그레이션이 포함되는가? → 포함된다면 운영 DB 백업부터
- [ ] 서버의 git 브랜치가 내가 배포하려는 브랜치와 일치하는가? (`git branch -vv`)
- [ ] `git pull`이 fast-forward로 끝나는가? (충돌이 있다면 왜 서버 쪽에 내가 모르는 변경이 있는지부터 파악)

## 마무리

이런 제약이 있는 호스팅은 사실 드물지 않습니다. 저렴하고, 이미 익숙하고, 국내 결제/정산이 편하다는 이유로 선택하는 경우가 많기 때문입니다. "php-cli가 막혀 있다"는 사실 하나만 알고 시작하면, 이번 장에서 다룬 방법들로 충분히 git 기반 배포 흐름을 유지할 수 있습니다. 핵심은 하나입니다 — **서버에서 안 되는 걸 억지로 되게 만들려 하지 말고, 되는 걸(git, 파일 복사) 조합해서 안 되는 걸(php-cli) 우회하는 것.**
