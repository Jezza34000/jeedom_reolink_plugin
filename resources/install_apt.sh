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
GO2RTC_VERSION="1.9.15"
BIN_DIR="${BASE_DIR}/bin"
VENV_DIR="${BASE_DIR}/venv"

# Sequence inlined (instead of launchInstall) so go2rtc is ready before post()
# removes the dependancy progress file — otherwise Jeedom starts the daemon mid-download.
pre
step 5 "Nettoyage APT"
try apt-get clean
step 10 "Mise à jour APT"
try apt-get update

autoSetupVenv

step 70 "Installation des paquets python nécessaires"
try ${VENV_DIR}/bin/python3 -m pip install --upgrade -r ${BASE_DIR}/requirements.txt

step 85 "Téléchargement de go2rtc ${GO2RTC_VERSION}"
mkdir -p "${BIN_DIR}"
ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
case "${ARCH}" in
  amd64|x86_64) GO2RTC_ARCH="amd64" ;;
  arm64|aarch64) GO2RTC_ARCH="arm64" ;;
  armhf|armel|armv7l|arm) GO2RTC_ARCH="arm" ;;
  i386|i686) GO2RTC_ARCH="i386" ;;
  *) GO2RTC_ARCH="" ;;
esac
if [ -z "${GO2RTC_ARCH}" ]; then
  echo "Architecture non supportée pour go2rtc: ${ARCH}"
  echo 1 > ${TMPFOLDER}/hasError.$$
else
  GO2RTC_URL="https://github.com/AlexxIT/go2rtc/releases/download/v${GO2RTC_VERSION}/go2rtc_linux_${GO2RTC_ARCH}"
  echo "Downloading go2rtc ${GO2RTC_VERSION} (${GO2RTC_ARCH})..."
  if wget -q -O "${BIN_DIR}/go2rtc.tmp" "${GO2RTC_URL}" \
    && [ -s "${BIN_DIR}/go2rtc.tmp" ]; then
    chmod +x "${BIN_DIR}/go2rtc.tmp"
    mv -f "${BIN_DIR}/go2rtc.tmp" "${BIN_DIR}/go2rtc"
    rm -f "${BIN_DIR}/go2rtc.yaml"
  else
    rm -f "${BIN_DIR}/go2rtc.tmp"
    echo "go2rtc download failed"
    echo 1 > ${TMPFOLDER}/hasError.$$
  fi
fi

step 90 "Rétablissement des droits des dossiers et fichiers"
chown -Rh www-data:www-data "${VENV_DIR}" "${BIN_DIR}"

step 95 "Résumé des paquets installés"
${VENV_DIR}/bin/python3 -m pip freeze
if [ -x "${BIN_DIR}/go2rtc" ]; then
  "${BIN_DIR}/go2rtc" -v || true
fi

post
