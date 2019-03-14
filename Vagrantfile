# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure("2") do |config|
  config.vm.box = "ubuntu/bionic64"

  config.vm.network "forwarded_port", guest: 8080, host: 8080

  config.vm.synced_folder ".", "/var/www/html/remotelabz-worker", owner: "www-data", group: "www-data"

  config.vm.provision "shell", path: "vagrant/provision.sh"
end
