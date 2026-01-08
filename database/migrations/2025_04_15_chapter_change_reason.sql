ALTER TABLE chapter_change_requests
  ADD COLUMN rejection_reason TEXT NULL AFTER status;
