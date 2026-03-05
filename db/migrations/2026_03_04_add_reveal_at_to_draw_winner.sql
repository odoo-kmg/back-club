ALTER TABLE draw_winner
  ADD COLUMN reveal_at DATETIME NULL AFTER selected_at;

CREATE INDEX idx_draw_winner_draw_reveal
  ON draw_winner (draw_id, reveal_at, inactive_at);
