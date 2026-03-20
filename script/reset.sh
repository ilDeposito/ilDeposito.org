#!/bin/bash

spinner() {
  local pid=$1
  local msg="$2"
  local spin='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏'
  local i=0
  tput civis 2>/dev/null
  while kill -0 $pid 2>/dev/null; do
    i=$(( (i+1) % 10 ))
    printf "\r%s %s" "$msg" "${spin:$i:1}"
    sleep 0.1
  done
  tput cnorm 2>/dev/null
}

run_task() {
  local msg="$1"
  shift
  echo -n "[reset] $msg "
  ("$@" > /dev/null 2>reset.err) &
  local pid=$!
  spinner $pid "[reset] $msg"
  wait $pid
  local status=$?
  if [ $status -eq 0 ]; then
    printf "\r[reset] %s ✓\n" "$msg"
    rm -f reset.err
  else
    printf "\r[reset] %s ✗\n" "$msg"
    cat reset.err
    rm -f reset.err
    exit $status
  fi
}

run_task "Elimino progetto DDEV..." ddev delete -y
run_task "Avvio progetto DDEV..." ddev start
run_task "Installo Drupal minimal..." ddev drush si minimal -y --account-name=admin --account-pass=admin --account-mail=admin@example.com --site-name="ilDeposito.org"
run_task "Imposto UUID site..." ddev drush cset system.site uuid 541fc72c-bd22-44ca-a91e-f8f697223f94 -y
run_task "Importo configurazioni..." ddev drush cim -y
run_task "Aggiorno database..." ddev drush updb -y
run_task "Creo i contenuti di default..." ddev drush ildeposito:create-default-media
run_task "Controllo se ci sono traduzioni..." ddev drush locale:check
run_task "Aggiorno le traduzioni..." ddev drush locale:update
run_task "Importo di nuovo le configurazioni..." ddev drush cim -y
run_task "Indicizzo i contenuti..." ddev drush search-api:index
run_task "Ricostruisco la cache..." ddev drush cr

# Messaggio finale con spunta positiva
echo -e "✔ La versione locale de ilDeposito.org è pronta!"
