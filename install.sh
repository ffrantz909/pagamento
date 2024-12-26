#!/bin/bash

echo "Iniciando!"

sleep 2

echo "Terminando os serviços ativos!"

sleep 1

killall php

echo "Instalando WE!"

sleep 1

cd /var/www/

rm -r _we*

mv /var/www/WE /var/www/WE_OLD

wget –no-check-certificate --http-user=imply --http-password=ipydebian distros.imply.com/downloads/WE/install_we.sh

arquivo="install_we.sh"

# Verifica se o arquivo existe
if [ -f "$arquivo" ]; then
    # Faz a substituição no conteúdo do arquivo
    sed -i 's/read pasta_instalacao_we/pasta_instalacao_we="WE"/' "$arquivo"
    echo "Alteração realizada com sucesso no arquivo: $arquivo"
    sed -i 's/read opcao/opcao="1"/' "$arquivo"
    echo "Alteração realizada com sucesso no arquivo: $arquivo"
else
    echo "Erro: O arquivo $arquivo não foi encontrado!"
fi

sh install_we.sh

mkdir /var/www/WE/lic.d/

echo "Reinstalando pagamento"

sleep 2

wget https://github.com/ffrantz909/pagamento/raw/refs/heads/main/IPYJavaAuttar.jar

wget https://github.com/ffrantz909/pagamento/raw/refs/heads/main/class.TEFAuttar.php

mv class.TEFAuttar.php /var/www/WE/apps/Pagamento/

mv IPYJavaAuttar.jar /var/www/WE/apps/Pagamento/

echo "Executar o check.install"

sleep 2

php /var/www/WE/check.install.shell.php sinc_licenca
php background.php -classe=Service -metodo=restartAll

echo "Reiniciando em 3...2...1.."

Sleep 2

echo "##################################################
#   ______                                       #
#  |          /\      |       |\    /|    /\     #
#  |   	     /  \     |       | \  / |   /  \    #
#  |	    /----\    |       |  \/  |  /----\   #
#  |	   /      \   |	      |      | /      \  #
#  |_____ /        \  |______ |      |/        \ #
#                                                #
##################################################"

Sleep 2

echo "EXECUTE O CHECK.INSTALL PELO http://127.0.0.1/WE/check.install.html"
echo "E REINICIE O TCI ANTES DE TESTAR"
exit 0