CREATE TABLE llx_speakdo_nonce (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  nonce_hash char(64) NOT NULL,
  datec datetime NOT NULL,
  expires_at datetime NOT NULL
) ENGINE=innodb;
