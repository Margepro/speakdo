ALTER TABLE llx_speakdo_enrollment ADD UNIQUE INDEX uk_speakdo_enrollment_token (token_hash);
ALTER TABLE llx_speakdo_enrollment ADD INDEX idx_speakdo_enrollment_user (fk_user);
ALTER TABLE llx_speakdo_enrollment ADD INDEX idx_speakdo_enrollment_status (entity, status, expires_at);
