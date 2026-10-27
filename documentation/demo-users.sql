-- Demo accounts for the portfolio quick-login buttons (frontend login page).
-- Safe to run multiple times: existing demo users get their password/role reset.

INSERT INTO `users` (`name`, `email`, `password`, `role`) VALUES
  ('Demo Client', 'demo.client@vickimmo.test', '$2y$12$dsyQ4P3vNel0ZnAGSnl/Wenp2mxFaq9fsZJitNv/4uBCYUVAgqb2K', 'client')
  ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `role` = VALUES(`role`);

INSERT INTO `users` (`name`, `email`, `password`, `role`) VALUES
  ('Demo Employee', 'demo.employee@vickimmo.test', '$2y$12$xEVMmshR2tNNC0CNcwVEpOGDxYqlacoUtedjXPeUyP5EfFAc1WxY6', 'employee')
  ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `role` = VALUES(`role`);

INSERT INTO `users` (`name`, `email`, `password`, `role`) VALUES
  ('Demo Admin', 'demo.admin@vickimmo.test', '$2y$12$XpWjFo3RfN9SzjGOIoyETu8cj/O.4mpLeDOGwheBOkX9qNhYFGc/m', 'admin')
  ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `role` = VALUES(`role`);

-- Link the demo employee to Demeester Vastgoed (agency 19)
INSERT IGNORE INTO `agencies_employees` (`user_id`, `agency_id`)
  SELECT `id`, 19 FROM `users` WHERE `email` = 'demo.employee@vickimmo.test';
