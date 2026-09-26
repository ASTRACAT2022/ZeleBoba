-- The current Rich Message chat card can be refreshed after background
-- provisioning without sending a second notification into the conversation.
CREATE TABLE telegram_ui_state (
 user_id VARCHAR(32) PRIMARY KEY REFERENCES users(id),
 chat_id VARCHAR(30) NOT NULL,
 message_id BIGINT NOT NULL,
 screen VARCHAR(64) NOT NULL,
 context_id VARCHAR(32),
 updated_at BIGINT NOT NULL
);
CREATE INDEX telegram_ui_context ON telegram_ui_state(screen,context_id);
