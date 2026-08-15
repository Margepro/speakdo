CREATE TABLE llx_speakdo_device (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  public_id varchar(36) NOT NULL,
  fk_user integer NOT NULL,
  label varchar(128) NOT NULL,
  platform varchar(32) NULL,
  pwa_version varchar(32) NULL,
  public_key text NULL,
  status varchar(20) NOT NULL DEFAULT 'ACTIVE',
  datec datetime NOT NULL,
  last_seen_at datetime NULL,
  revoked_at datetime NULL,
  fk_user_revoke integer NULL
) ENGINE=innodb;
