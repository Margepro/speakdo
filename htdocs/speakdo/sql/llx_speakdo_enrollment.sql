CREATE TABLE llx_speakdo_enrollment (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  token_hash char(64) NOT NULL,
  fk_user integer NOT NULL,
  fk_user_author integer NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'PENDING',
  datec datetime NOT NULL,
  expires_at datetime NOT NULL,
  consumed_at datetime NULL,
  ip_created varchar(45) NULL
) ENGINE=innodb;
