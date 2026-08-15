ALTER TABLE llx_speakdo_device ADD UNIQUE INDEX uk_speakdo_device_public_id (public_id);
ALTER TABLE llx_speakdo_device ADD INDEX idx_speakdo_device_user (entity, fk_user);
ALTER TABLE llx_speakdo_device ADD INDEX idx_speakdo_device_status (entity, status);
