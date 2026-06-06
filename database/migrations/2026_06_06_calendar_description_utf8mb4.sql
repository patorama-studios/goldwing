-- WYSIWYG editor on calendar events now stores HTML with potential emoji
-- characters. Make sure the description column can hold 4-byte UTF-8
-- (e.g. 😀, 🏍️) by converting it to utf8mb4. The rest of the table can
-- stay on whatever it is — only this column needs the wider charset.

ALTER TABLE calendar_events
  MODIFY COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
