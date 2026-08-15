ALTER TABLE llx_speakdo_nonce ADD UNIQUE INDEX uk_speakdo_nonce (entity, nonce_hash);
ALTER TABLE llx_speakdo_nonce ADD INDEX idx_speakdo_nonce_expiry (expires_at);
