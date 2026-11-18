#!/bin/bash
# Instala o runner self-hosted do GitHub Actions NA VM, como serviço.
# Assim os workflows rodam no seu servidor (de graça, ilimitado) e o repo
# pode ficar privado sem gastar minutos pagos do GitHub.
set -eu
TOKEN="${1:?token de registro do runner}"
URL="https://github.com/DrMetzger/Primeerp"
DIR=/opt/actions-runner
U=github-runner

apt-get update -qq && apt-get install -y -qq libicu74 curl tar jq >/dev/null 2>&1 || apt-get install -y -qq libicu70 curl tar jq >/dev/null 2>&1 || true
id -u $U >/dev/null 2>&1 || useradd -m -s /bin/bash $U
# o runner precisa de sudo sem senha pros workflows (apt-get, etc.)
echo "$U ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/github-runner && chmod 440 /etc/sudoers.d/github-runner
mkdir -p $DIR && chown $U:$U $DIR
cd $DIR
if [ ! -f ./config.sh ]; then
  VER=$(curl -s https://api.github.com/repos/actions/runner/releases/latest | jq -r .tag_name | sed 's/^v//')
  echo "baixando runner $VER"
  curl -sL -o runner.tar.gz "https://github.com/actions/runner/releases/download/v${VER}/actions-runner-linux-x64-${VER}.tar.gz"
  tar xzf runner.tar.gz && rm -f runner.tar.gz
  chown -R $U:$U $DIR
fi
# (re)configura
if [ -f .runner ]; then sudo -u $U ./config.sh remove --token "$TOKEN" >/dev/null 2>&1 || true; fi
sudo -u $U ./config.sh --unattended --url "$URL" --token "$TOKEN" --name primeerp-vm --labels self-hosted,vm --replace
./svc.sh install $U
./svc.sh start
sleep 2
./svc.sh status | head -5
echo "RUNNER-OK"
