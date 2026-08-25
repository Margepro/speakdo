CREATE TABLE llx_speakdo_tenant_bootstrap (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  bootstrap_id varchar(36) NOT NULL,
  challenge varchar(128) NOT NULL,
  installation_id varchar(36) NOT NULL,
  expires_at datetime NOT NULL,
  datec datetime NOT NULL
) ENGINE=innodb;
