# 부록 A. Windows + WSL 개발 환경 만들기

이 책은 1장부터 **PHP와 Composer가 이미 설치되어 있다는 전제**로 시작합니다. macOS나 Linux를 쓴다면 대개 이미 있거나 한 줄 설치로 끝나지만, Windows는 사정이 다릅니다. 이 부록은 Windows에서 WSL(Windows Subsystem for Linux)을 이용해 1장을 시작하기 전 상태까지 만드는 과정을 다룹니다.

## 왜 WSL인가

Windows에 XAMPP 같은 올인원 패키지를 까는 방법도 있지만, 추천하지 않습니다. CI4 커뮤니티나 공식 문서, 그리고 실제 배포 환경(13장에서 다루는 공유 호스팅 포함) 전부 Linux 기준이라, 처음부터 Linux 셸에 익숙해지는 게 장기적으로 낫습니다. WSL2는 Windows 안에서 진짜 Linux 커널을 돌리는 기능이라, "거의 리눅스 서버와 똑같은 환경"을 로컬에서 그대로 쓸 수 있습니다.

## 1단계: WSL2 설치

Windows 10(버전 2004 이상) / Windows 11에서는 PowerShell을 **관리자 권한**으로 열고 한 줄이면 끝납니다.

```powershell
wsl --install
```

별도 옵션을 주지 않으면 Ubuntu가 기본으로 설치됩니다. 설치가 끝나면 재부팅을 요구할 수 있습니다. 재부팅 후 시작 메뉴에서 "Ubuntu"를 실행하면 리눅스 사용자명/비밀번호를 설정하는 초기 화면이 뜹니다.

이미 WSL이 설치되어 있는데 버전만 확인하고 싶다면:

```powershell
wsl --list --verbose
```

`VERSION` 칸이 `2`인지 확인하세요. `1`이면 `wsl --set-version Ubuntu 2`로 올릴 수 있습니다.

## 2단계: Ubuntu 안에서 패키지 업데이트

여기서부터는 전부 **Ubuntu 터미널 안에서** 실행합니다(Windows PowerShell이 아닙니다).

```bash
sudo apt update && sudo apt upgrade -y
```

## 3단계: PHP 설치

Ubuntu 기본 저장소의 PHP 버전은 최신이 아닐 수 있습니다. [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) PPA를 추가하면 원하는 버전을 고를 수 있습니다.

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
```

이 책에서 필요한 PHP 확장까지 한 번에 설치합니다.

```bash
sudo apt install -y php8.3 php8.3-cli php8.3-common \
    php8.3-intl php8.3-mbstring php8.3-sqlite3 php8.3-xml php8.3-curl php8.3-gd
```

- `intl`, `mbstring`: 다국어/문자열 처리 (한글 다루는 데 필수)
- `sqlite3`: 1장에서 쓰는 SQLite DB 드라이버
- `xml`, `curl`: CI4 프레임워크 자체와 6장 이후 외부 API 호출에 필요
- `gd`: 이미지 처리가 들어간 예제(Playground의 이미지 처리 예제 등)에 필요

설치 확인:

```bash
php -v
php -m | grep -iE "intl|mbstring|sqlite3|curl|gd"
```

## 4단계: Composer 설치

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

## 5단계: 프로젝트는 반드시 WSL 파일시스템 안에 둘 것

가장 중요하고, 가장 많이 놓치는 부분입니다. WSL에서는 Windows 드라이브가 `/mnt/c/...`처럼 보여서 접근할 수 있지만, **이 경로 위에서 작업하면 파일 I/O 속도가 크게 느려집니다.** CI4처럼 파일을 많이 읽고 쓰는 프레임워크(뷰 캐시, 세션, 로그 등)는 이 차이가 체감될 정도로 큽니다.

```bash
# 나쁜 예 — Windows 드라이브 위에서 작업 (느림)
cd /mnt/c/Users/me/projects/ai-board

# 좋은 예 — WSL 자체 파일시스템 (빠름)
cd ~
composer create-project codeigniter4/appstarter ai-board
cd ai-board
```

탐색기에서 WSL 파일에 접근하고 싶으면 Windows 탐색기 주소창에 `\\wsl$`를 입력하면 됩니다. 하지만 작업은 어디까지나 `~` 아래, WSL 쪽에서 하세요.

## 6단계: 개발 서버 실행 & 브라우저 접속

1장에서처럼 그대로 실행하면 됩니다.

```bash
php spark serve
```

WSL2는 네트워킹을 Windows와 투명하게 공유하기 때문에, Windows 쪽 브라우저에서 그냥 `http://localhost:8080`으로 접속하면 됩니다. 별도의 포트 포워딩 설정이 필요 없습니다.

## 7단계 (선택): VS Code로 편집하기

Windows에 설치한 VS Code에 **"WSL" 확장**(Microsoft 공식)을 설치하면, WSL 안의 프로젝트 폴더를 그대로 열어서 편집할 수 있습니다.

```bash
cd ~/ai-board
code .
```

`code .`를 치면 VS Code가 자동으로 "WSL에 연결된" 상태로 열립니다(창 좌측 하단에 "WSL: Ubuntu" 표시가 뜨는지 확인하세요). 이렇게 하면 터미널도, 파일도, 확장 프로그램 실행도 전부 WSL 쪽에서 돌아가서 성능 문제가 없습니다. Windows 쪽 VS Code로 `\\wsl$` 경로를 직접 여는 것보다 이 방법이 훨씬 낫습니다.

## 체크리스트

- [ ] `wsl --list --verbose`에서 사용 중인 배포판이 VERSION 2인가
- [ ] `php -v`가 Windows가 아니라 WSL 터미널에서 실행한 결과인가
- [ ] 프로젝트 경로가 `/mnt/c/...`가 아니라 `~/...`(WSL 자체 파일시스템)인가
- [ ] VS Code가 "WSL: Ubuntu"로 연결된 상태로 열리는가

여기까지 끝나면 [1장](01-intro-setup.md)부터 그대로 따라가면 됩니다.
