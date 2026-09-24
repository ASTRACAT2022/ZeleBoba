CREATE TABLE broadcast_deliveries (
 broadcast_id VARCHAR(32) NOT NULL REFERENCES broadcast_history(id),
 chat_id VARCHAR(30) NOT NULL,
 outcome VARCHAR(20) NOT NULL CHECK(outcome IN ('sent','failed')),
 completed_at BIGINT NOT NULL,
 PRIMARY KEY(broadcast_id,chat_id)
);
