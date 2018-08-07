# racktables-ansible-dynamic-inventory
Script to create a dynamic ansible inventory from scraping an Racktables site

```
myusername@myhost:~/op5-ansible-dynamic-inventory# ./get_inventory.php
Usage: get_inventory.php [OPTION]
racktables-ansible-dynamic-inventory opengd@2018

--list                          get json list of op5 hosts
--host=host                     get ansible meta variable from op5 host
--static                        create inventory file from op5 hosts
--static_filename=FILENAME      filename of static inventory
--config_file=CONFIG_FILE       filepath to config file
--verbose                       show verbose data and errors
--help                          show this help message
```

## Getting Started

### Prerequisites

To run this script you will need PHP and the PHP cURL module.

```
# Install php curl module for PHP 7.2 on Ubuntu
sudo apt-get install php7.2-curl
```

### Installing

Clone to git rep to get the script and example config file.

```
git clone https://github.com/opengd/racktables-ansible-dynamic-inventory.git
```
This will retriev the git repo and you can find the php 

## Usage

racktables-ansible-dynamic-inventory script can be config by using a config json file or you can change the config inside the php script file. Using a seperate config file (default: config.json) is recommended.

This is the example config.json, you can find it as config.json.example in the op5-ansible-dynamic-inventory folder. Copy this file and rename it to config.json, and edit the copy to create your own config file.

The filter part that is used to get list of host from racktables is using php regex. For more information about filtering please check php's documentation [PHP regex Pattern Syntax](http://php.net/manual/en/reference.pcre.pattern.syntax.php).

``` javascript
{
    "racktables": {
        "list_query":[
            {
                "userpwd": "username:password",
                "host": "http:\/\/your.racktables.host",
                "list_api": "\/racktables\/index.php?page=depot",
                "host_api": "\/racktables\/index.php?page=object&tab=default&object_id=",
                "host_filters": {
                    "some": {
                        "filter": ["\/your_regexp\/"],
                        "host_vars":{
                            "ansible_port": [22, 22022],
                            "ansible_host": "ADDRESS" 
                        },
                        "check_ansible_port": true
                    }
                }
            }
        ],
        "host_query":[
            {
                "userpwd": "username:password",
                "host": "http:\/\/your.racktables.host",
                "list_api": "\/racktables\/index.php?page=depot",
                "host_api": "\/racktables\/index.php?page=object&tab=default&object_id=",
                "host_vars":{
                    "ansible_port": [22, 22022],
                    "ansible_host": "ADDRESS" 
                },
                "check_ansible_port": true
            }
        ]
    }
}
```

### Ansible

To run an Ansible playbook or a direct command using the script, you will pass the script as the inventory list, just like it was a static list of host.

```
ansible all -l /the/path/to/get_inventory.php -m ping
```

Example, if the script and config.json is in current directory:
```
ansible all -l get_inventory.php -m ping
```

This will retriev a host list based on you config.json and push it to Ansible for use.

### Generate a static host list

Generate a static host list will use the same config.json as default but you can also specify a other config file to use to create the list.

```
./get_inventory.php --static
```
This will create a static host list named op5_hosts.ansible based on default config.json.

```
./get_inventory.php --static --config_file=myconfig.json
```
This will create a static host list named op5_hosts.ansible based on the myconfig.json file.

```
./get_inventory.php --static --config_file=myconfig.json --static_filename=myhosts.ansible
```
This will create static host list named myhosts.ansible based on the myconfig.json file.

## Built With

* [PHP](http://http://php.net) - PHP is a popular general-purpose scripting language that is especially suited to web development.
* [Racktables](https://www.racktables.org/) - Racktables
* [Ansible](https://www.ansible.com/) - Ansible automation

## Contributing

Please read [CONTRIBUTING.md](https://gist.github.com/PurpleBooth/b24679402957c63ec426) for details on our code of conduct, and the process for submitting pull requests to us.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details