######################### INCLUSION LIB ##########################
BASE_DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/Mips2648/dependance.lib/master/dependance.lib --no-cache -O ${BASE_DIR}/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath ${BASE_DIR}/..)")
LANG_DEP=en
. ${BASE_DIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/Mips2648/dependance.lib/master/pyenv.lib --no-cache -O ${BASE_DIR}/pyenv.lib &>/dev/null
. ${BASE_DIR}/pyenv.lib
##################################################################

TARGET_PYTHON_VERSION="3.9"

launchInstall

GO2RTC_VERSION="1.9.15"
BIN_DIR="${BASE_DIR}/bin"
mkdir -p "${BIN_DIR}"
ARCH=$(uname -m)
case "${ARCH}" in
  aarch64|arm64) GO2RTC_ARCH="arm64" ;;
  armv7l|armhf) GO2RTC_ARCH="arm" ;;
  x86_64|amd64) GO2RTC_ARCH="amd64" ;;
  i386|i686) GO2RTC_ARCH="i386" ;;
  *) GO2RTC_ARCH="" ;;
esac
if [ -n "${GO2RTC_ARCH}" ]; then
  GO2RTC_URL="https://github.com/AlexxIT/go2rtc/releases/download/v${GO2RTC_VERSION}/go2rtc_linux_${GO2RTC_ARCH}"
  echo "Downloading go2rtc ${GO2RTC_VERSION} (${GO2RTC_ARCH})..."
  wget -q -O "${BIN_DIR}/go2rtc" "${GO2RTC_URL}" && chmod +x "${BIN_DIR}/go2rtc" || echo "go2rtc download failed (optional)"
fi
cat > "${BIN_DIR}/go2rtc.yaml" <<'EOF'
api:
  listen: "127.0.0.1:1984"
webrtc:
  listen: ":8555"
EOF