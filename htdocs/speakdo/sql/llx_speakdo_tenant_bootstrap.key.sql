ALTER TABLE llx_speakdo_tenant_bootstrap ADD UNIQUE INDEX uk_speakdo_tenant_bootstrap_id (bootstrap_id);
ALTER TABLE llx_speakdo_tenant_bootstrap ADD INDEX idx_speakdo_tenant_bootstrap_expiry (expires_at);
