<?php
namespace App\Services;

class NotificationService
{
    public static function definitions(): array
    {
        return [
            'member_set_password' => [
                'label' => 'Set password email (new member)',
                'description' => 'Sent when an admin creates a member account.',
                'trigger' => 'Admin approves a membership application.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link', 'first_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Welcome to the Australian Goldwing Association — let\'s get you set up!',
                    'body' => '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#1c1a17;line-height:1.3;">G\'day {{first_name}},</p><p style="margin:0 0 24px;font-size:16px;color:#5a5a55;line-height:1.6;">Welcome to the Australian Goldwing Association\'s member portal — we\'re thrilled to have you on board with us!</p><hr style="border:none;border-top:1px solid #e8e3d7;margin:0 0 24px;"><p style="margin:0 0 8px;font-size:15px;font-weight:600;color:#1c1a17;">One quick step before you can log in:</p><p style="margin:0 0 28px;font-size:15px;color:#5a5a55;line-height:1.6;">You\'ll need to create your password. Press the button below to get started — it only takes a moment.</p><table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;"><tr><td style="background:#9e9140;border-radius:8px;"><a href="{{reset_link}}" style="display:inline-block;padding:14px 36px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;letter-spacing:0.02em;">Set Your Password &rarr;</a></td></tr></table><p style="margin:0 0 24px;font-size:13px;color:#9a9a94;line-height:1.5;">This link is valid for 48 hours. If you have any trouble, reply to this email or contact us at <a href="mailto:webmaster@goldwing.org.au" style="color:#9e9140;text-decoration:none;">webmaster@goldwing.org.au</a></p><table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f1e8;border-radius:10px;overflow:hidden;"><tr><td style="padding:20px 24px 8px;"><p style="margin:0 0 16px;font-size:13px;font-weight:700;color:#9e9140;text-transform:uppercase;letter-spacing:0.1em;">Once you\'re in, you can</p></td></tr><tr><td style="padding:0 24px 20px;"><table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F3CD;&#xFE0F; Manage your bikes</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F4D6; Read Wings Magazine</td></tr><tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F4B3; Manage your membership</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F6D2; Shop the members store</td></tr><tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F4C5; Browse upcoming events</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">&#x1F4E2; Post on the notice board</td></tr></table></td></tr></table>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_password_reset_admin' => [
                'label' => 'Password reset (admin initiated)',
                'description' => 'Sent when an admin requests a password reset for a member.',
                'trigger' => 'Admin clicks "Send reset link" on a member profile.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Password reset request',
                    'body' => '<p>Reset your password:</p><p><a href="{{reset_link}}">Reset password</a></p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_password_reset_self' => [
                'label' => 'Password reset (self-service)',
                'description' => 'Sent when a member requests a password reset from the login screen.',
                'trigger' => 'Member submits the reset form.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Password Reset',
                    'body' => '<p>Reset your password:</p><p><a href="{{reset_link}}">Reset Password</a></p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'security_email_otp' => [
                'label' => 'Login verification code (email OTP)',
                'description' => 'Sent when a member must verify a login with an email OTP.',
                'trigger' => 'Member logs in and requires email OTP verification.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['otp_code', 'expires_minutes', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your login verification code',
                    'body' => '<p>Hello {{member_name}},</p><p>Your verification code is:</p><p style="font-size:24px; font-weight:bold; letter-spacing:2px;">{{otp_code}}</p><p>This code expires in {{expires_minutes}} minutes.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_approved' => [
                'label' => 'Membership approved',
                'description' => 'Sent when a membership application is approved.',
                'trigger' => 'Admin approves an application.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['payment_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership approved - Next Steps',
                    'body' => '<p>Your membership has been approved! If you have not paid yet, please complete your payment:</p><p><a href="{{payment_link}}" style="display:inline-block;padding:10px 20px;background:#0055ff;color:#fff;text-decoration:none;border-radius:4px;">Pay now</a></p><p>You will also receive a separate email containing a link to set up your account password so you can access the portal.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_created' => [
                'label' => 'Membership order created',
                'description' => 'Sent when a membership order is created or re-sent.',
                'trigger' => 'Membership order is created.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'payment_link', 'payment_method', 'bank_transfer_instructions', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Finish your AGA membership payment — order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p>'
                        . '<p>Your Australian Goldwing Association membership order <strong>#{{order_number}}</strong> is ready to finalise. Press the button below to complete your payment and activate your membership:</p>'
                        . '<p style="text-align:center;margin:28px 0;"><a href="{{payment_link}}" style="display:inline-block;padding:14px 36px;background:#0055ff;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;">Pay Now</a></p>'
                        . '<p style="color:#6b7280;font-size:13px;text-align:center;">Or copy and paste this link into your browser:<br><a href="{{payment_link}}" style="color:#0055ff;word-break:break-all;">{{payment_link}}</a></p>'
                        . '<p style="margin-top:24px;">Payment method on file: <strong>{{payment_method}}</strong></p>'
                        . '<p>{{bank_transfer_instructions}}</p>'
                        . '<p style="margin-top:24px;color:#6b7280;font-size:13px;">If you have any trouble paying, reply to this email and a webmaster will help.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_payment_received' => [
                'label' => 'Membership payment received',
                'description' => 'Sent when a membership payment is received.',
                'trigger' => 'Stripe webhook or admin approval.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Payment received for order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>We have received your membership payment for order <strong>#{{order_number}}</strong>.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_approved' => [
                'label' => 'Membership order approved',
                'description' => 'Sent when an admin approves a membership order.',
                'trigger' => 'Admin approves a pending membership order.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership order approved #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership order <strong>#{{order_number}}</strong> has been approved.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_rejected' => [
                'label' => 'Membership order rejected',
                'description' => 'Sent when an admin rejects a membership order.',
                'trigger' => 'Admin rejects a pending membership order.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'rejection_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership order rejected #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership order <strong>#{{order_number}}</strong> was rejected.</p><p>Reason: {{rejection_reason}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_payment_failed' => [
                'label' => 'Membership payment failed',
                'description' => 'Sent when a membership payment fails.',
                'trigger' => 'Stripe payment fails.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'payment_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Payment failed for order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership payment for order <strong>#{{order_number}}</strong> failed.</p><p><a href="{{payment_link}}">Try payment again</a></p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_refund_processed' => [
                'label' => 'Membership refund processed',
                'description' => 'Sent to the member when a refund is issued against a membership order.',
                'trigger' => 'Stripe charge.refunded webhook or admin refunds via the admin portal.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'refund_amount', 'refund_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Refund processed for membership order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>A refund has been processed for your membership order <strong>#{{order_number}}</strong>.</p><p>Amount: {{refund_amount}}<br>Reason: {{refund_reason}}</p><p>If you have any questions, reply to this email.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_subscription_cancelled' => [
                'label' => 'Membership subscription cancelled',
                'description' => 'Sent to the member when a subscription is cancelled by Stripe (admin / customer / dunning).',
                'trigger' => 'Stripe customer.subscription.updated webhook with status canceled / unpaid / incomplete_expired.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'payment_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your membership subscription has been cancelled',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership subscription (order <strong>#{{order_number}}</strong>) has been cancelled and your membership is no longer active.</p><p>To renew, sign in and start a new membership: <a href="{{payment_link}}">Renew now</a>.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_admin_pending_approval' => [
                'label' => 'Admin: membership payment awaiting approval',
                'description' => 'Sent to admins when a bank transfer membership order needs approval.',
                'trigger' => 'Membership order created with bank transfer.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'Membership payment pending approval #{{order_number}}',
                    'body' => '<p>Membership order <strong>#{{order_number}}</strong> for {{member_name}} requires approval.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_migration_invite' => [
                'label' => 'Manual migration invite',
                'description' => 'Sent when an admin invites a member to complete a manual migration.',
                'trigger' => 'Admin clicks "Send Manual Migrate Form" on a member profile.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['migration_link', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Complete your membership setup',
                    'body' => '<p>Hello {{member_name}},</p><p>Please complete your membership setup:</p><p><a href="{{migration_link}}">Finish setup</a></p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_activated_confirmation' => [
                'label' => 'Membership activated',
                'description' => 'Sent when a membership is activated without a Stripe payment.',
                'trigger' => 'Manual migration or admin manual activation.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['membership_type', 'renewal_date', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your membership is active',
                    'body' => '<p>Hello {{member_name}},</p><p>Your {{membership_type}} membership is now active.</p><p>Renewal date: {{renewal_date}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_of_year_nomination_receipt' => [
                'label' => 'Member of the Year nomination receipt',
                'description' => 'Sent to the nominator after they submit a Member of the Year nomination.',
                'trigger' => 'Member submits a nomination.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['nominator_name', 'nominee_name', 'submission_year', 'nomination_details', 'submission_id'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Member of the Year nomination received',
                    'body' => '<p>Hello {{nominator_name}},</p><p>Thanks for submitting a Member of the Year nomination for {{nominee_name}} in {{submission_year}}.</p><p>We received the following details:</p><p>{{nomination_details}}</p><p>Reference: #{{submission_id}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_of_year_nomination_admin' => [
                'label' => 'Admin: Member of the Year nomination',
                'description' => 'Sent to admins when a Member of the Year nomination is submitted.',
                'trigger' => 'Member submits a nomination.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['nominator_name', 'nominator_email', 'nominee_name', 'nominee_chapter', 'submission_year', 'nomination_details', 'submission_id'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'New Member of the Year nomination #{{submission_id}}',
                    'body' => '<p>A new Member of the Year nomination was submitted for {{submission_year}}.</p><p><strong>Nominator:</strong> {{nominator_name}} ({{nominator_email}})<br><strong>Nominee:</strong> {{nominee_name}}<br><strong>Chapter:</strong> {{nominee_chapter}}</p><p><strong>Details:</strong><br>{{nomination_details}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_order_confirmation' => [
                'label' => 'Store order confirmation',
                'description' => 'Sent after a paid store order is received. Includes an itemised receipt with the products purchased, quantities, totals, and the delivery / pickup address.',
                'trigger' => 'Stripe checkout succeeds.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'address_html', 'items_html', 'totals_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your AGA store receipt — order #{{order_number}}',
                    'body' => '<p>Thanks for shopping with the Australian Goldwing Association!</p>'
                        . '<p>This email is your receipt for order <strong>#{{order_number}}</strong>. Below is everything you purchased.</p>'
                        . '<h3 style="margin-top:24px;margin-bottom:8px;font-size:16px;color:#111827;">Items in your order</h3>'
                        . '{{items_html}}'
                        . '<h3 style="margin-top:24px;margin-bottom:8px;font-size:16px;color:#111827;">Order totals</h3>'
                        . '{{totals_html}}'
                        . '<h3 style="margin-top:24px;margin-bottom:8px;font-size:16px;color:#111827;">Delivery</h3>'
                        . '{{address_html}}'
                        . '<p style="margin-top:24px;color:#6b7280;font-size:13px;">If anything in this receipt looks wrong, reply to this email and we\'ll sort it out. Tickets and shipping updates (when applicable) will arrive in separate emails.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_ticket_codes' => [
                'label' => 'Store ticket codes',
                'description' => 'Sent with ticket codes for ticketed items.',
                'trigger' => 'Store order contains tickets.',
                'category' => 'orders',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'ticket_list_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your ticket codes for order #{{order_number}}',
                    'body' => '<p>Your ticket codes are below for order <strong>#{{order_number}}</strong>.</p>{{ticket_list_html}}',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_shipping_update' => [
                'label' => 'Store shipping update',
                'description' => 'Sent when a shipment is saved for an order.',
                'trigger' => 'Admin adds tracking details.',
                'category' => 'orders',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'carrier', 'tracking_number'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Shipping update for order #{{order_number}}',
                    'body' => '<p>Your order <strong>#{{order_number}}</strong> has shipped.</p><p>Carrier: {{carrier}}<br>Tracking: {{tracking_number}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_admin_new_order' => [
                'label' => 'Admin: new store order',
                'description' => 'Sent to admins when a new paid store order arrives.',
                'trigger' => 'Stripe checkout succeeds.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'address_html', 'items_html', 'totals_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'New store order #{{order_number}}',
                    'body' => '<p>New paid store order <strong>#{{order_number}}</strong>.</p>{{address_html}}{{items_html}}{{totals_html}}',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_refund_processed' => [
                'label' => 'Store refund processed',
                'description' => 'Sent to the customer when a store refund is processed.',
                'trigger' => 'Admin processes a Stripe refund.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'refund_amount', 'refund_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Refund processed for order #{{order_number}}',
                    'body' => '<p>Your refund for order <strong>#{{order_number}}</strong> has been processed.</p><p>Amount: {{refund_amount}}<br>Reason: {{refund_reason}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_order_cancelled' => [
                'label' => 'Store order cancelled',
                'description' => 'Sent to the customer when their store order is cancelled by an admin.',
                'trigger' => 'Admin cancels a store order from the order view.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'cancel_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Order cancelled — #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your order <strong>#{{order_number}}</strong> has been cancelled.</p><p>Reason: {{cancel_reason}}</p><p>If this was a mistake, reply to this email and we will look into it.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'order_voided' => [
                'label' => 'Order voided',
                'description' => 'Sent to the customer / member when an admin voids one of their orders (membership or store). Voided orders are hidden from default views but can be restored.',
                'trigger' => 'Admin uses the void action on an order from the payments page, member tab, store orders list, or store order view.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'order_type_label', 'member_name', 'void_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Order #{{order_number}} has been voided',
                    'body' => '<p>Hello {{member_name}},</p><p>Your {{order_type_label}} order <strong>#{{order_number}}</strong> has been voided by our team.</p><p>Reason: {{void_reason}}</p><p>If you believe this is incorrect, reply to this email and we will look into it.</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'request_approved' => [
                'label' => 'Submission approved',
                'description' => 'Sent to the submitter when their request is approved by the webmaster.',
                'trigger' => 'Webmaster approves a notice, event, nomination, or other request from the notification hub.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['submitter_name', 'request_type', 'request_title', 'message', 'site_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your {{request_type}} submission has been approved',
                    'body' => '<p>Hi {{submitter_name}},</p><p>Good news — your {{request_type}} submission "<strong>{{request_title}}</strong>" has been approved and is now live.</p><div>{{message}}</div><p>Thanks,<br>{{site_name}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'request_denied' => [
                'label' => 'Submission denied',
                'description' => 'Sent to the submitter when their request is denied by the webmaster.',
                'trigger' => 'Webmaster denies a notice, event, nomination, or other request from the notification hub.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['submitter_name', 'request_type', 'request_title', 'message', 'site_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Update on your {{request_type}} submission',
                    'body' => '<p>Hi {{submitter_name}},</p><p>Your {{request_type}} submission "<strong>{{request_title}}</strong>" was not approved.</p><p><strong>Reason:</strong></p><div>{{message}}</div><p>If you have questions, reply to this email.</p><p>Thanks,<br>{{site_name}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'request_feedback' => [
                'label' => 'Submission feedback',
                'description' => 'Sent to the submitter when the webmaster sends feedback on a pending request.',
                'trigger' => 'Webmaster uses "Send feedback" on a request in the notification hub.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['submitter_name', 'request_type', 'request_title', 'message', 'site_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Feedback on your {{request_type}} submission',
                    'body' => '<p>Hi {{submitter_name}},</p><p>The webmaster has feedback on your {{request_type}} submission "<strong>{{request_title}}</strong>":</p><div style="border-left:3px solid #f59e0b;padding-left:12px;margin:12px 0;color:#374151;">{{message}}</div><p>Your submission is still pending — feel free to reply with any changes or questions.</p><p>Thanks,<br>{{site_name}}</p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'webmaster_new_request' => [
                'label' => 'New request awaiting review',
                'description' => 'Sent to the webmaster when a new request lands in the notification hub.',
                'trigger' => 'Member submits a notice, event, fallen wings tribute, etc.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['request_type', 'request_title', 'submitter_name', 'review_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'New {{request_type}} awaiting review',
                    'body' => '<p>A new {{request_type}} submission is waiting for review.</p><p><strong>Title:</strong> {{request_title}}<br><strong>Submitted by:</strong> {{submitter_name}}</p><p><a href="{{review_link}}" style="display:inline-block;padding:10px 20px;background:#0055ff;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Review now</a></p>',
                    'from_name' => 'Australian Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
        ];
    }

    public static function defaultCatalog(): array
    {
        $defaults = [];
        $globalSender = [
            'from_name' => SettingsService::getGlobal('notifications.from_name', 'Australian Goldwing Association'),
            'from_email' => SettingsService::getGlobal('notifications.from_email', 'no-reply@goldwing.org.au'),
            'reply_to' => SettingsService::getGlobal('notifications.reply_to', ''),
        ];
        foreach (self::definitions() as $key => $definition) {
            $definitionDefaults = $definition['defaults'] ?? [];
            $defaults[$key] = array_merge($globalSender, $definitionDefaults, [
                'is_mandatory' => (bool) ($definition['is_mandatory'] ?? false),
                'category'     => (string) ($definition['category'] ?? 'general'),
            ]);
        }
        return $defaults;
    }

    public static function getCatalogSettings(): array
    {
        $defaults = self::defaultCatalog();
        $saved = SettingsService::getGlobal('notifications.catalog', []);
        if (!is_array($saved)) {
            return $defaults;
        }
        $merged = $defaults;
        foreach ($saved as $key => $settings) {
            if (!isset($defaults[$key]) || !is_array($settings)) {
                continue;
            }
            // If the saved body is empty or contains raw byte-escape sequences
            // (e.g. \xe2\x80\x94) fall back to the clean code default.
            if (isset($settings['body']) && (
                trim((string) $settings['body']) === '' ||
                preg_match('/\\\\x[0-9a-fA-F]{2}/', (string) $settings['body'])
            )) {
                unset($settings['body']);
            }
            $merged[$key] = array_merge($defaults[$key], $settings);
        }
        return $merged;
    }

    public static function buildMessage(string $key, array $context): ?array
    {
        $catalog = self::getCatalogSettings();
        $settings = $catalog[$key] ?? null;
        if (!$settings || empty($settings['enabled'])) {
            return null;
        }
        $subject = self::applyTemplate((string) ($settings['subject'] ?? ''), $context);
        $body = self::applyTemplate((string) ($settings['body'] ?? ''), $context);
        return [
            'subject' => $subject,
            'body' => $body,
            'settings' => $settings,
        ];
    }

    public static function dispatch(string $key, array $context, array $options = []): bool
    {
        $catalog = self::getCatalogSettings();
        $settings = $catalog[$key] ?? null;
        if (!$settings) {
            return false;
        }
        $force = !empty($options['force']);
        $adminOverride = !empty($options['admin_override']);
        if (!$force && empty($settings['enabled'])) {
            return false;
        }
        if (!$force && !$adminOverride && !self::isGloballyEnabled()) {
            return false;
        }

        $memberId = self::resolveMemberId($context);
        $category = $settings['category'] ?? 'admin';
        $isMandatory = !empty($settings['is_mandatory']);

        $allowPrimary = self::shouldSendToMember($memberId, $category, $isMandatory, $options, $force);
        $recipients = self::resolveRecipients($settings, $context, $allowPrimary);
        if (!$recipients) {
            return false;
        }

        $subject = self::applyTemplate((string) ($settings['subject'] ?? ''), $context);
        $body = self::applyTemplate((string) ($settings['body'] ?? ''), $context);
        $sender = self::resolveSender($settings);
        if ($sender['from_email'] === '') {
            ActivityLogger::log('system', null, $memberId, 'notification.invalid_sender', [
                'notification' => $key,
            ]);
            return false;
        }

        $visibility = $options['visibility'] ?? ((string) ($settings['recipient_mode'] ?? 'primary') === 'admin' ? 'admin' : 'member');
        $metadata = [
            'member_id' => $memberId,
            'user_id' => $context['user_id'] ?? null,
            'notification_key' => $key,
            'category' => $category,
            'is_mandatory' => $isMandatory,
            'admin_override' => $adminOverride,
            'from_name' => $sender['from_name'],
            'from_email' => $sender['from_email'],
            'reply_to' => $sender['reply_to'],
            'context' => $context,
            'resend_of' => $options['resend_of'] ?? null,
            'visibility' => $visibility,
        ];

        $sent = false;
        foreach ($recipients as $recipient) {
            if (EmailService::send($recipient, $subject, $body, $metadata)) {
                $sent = true;
            }
        }

        if ($adminOverride && !empty($options['actor_id'])) {
            ActivityLogger::log('admin', (int) $options['actor_id'], $memberId, 'notification.override', [
                'notification' => $key,
            ]);
        }
        return $sent;
    }

    public static function sampleContext(string $key): array
    {
        $defaults = [
            'reset_link' => self::sampleEmailUrl('/member/reset_password_confirm.php?token=sample', 'https://example.org/reset?token=sample'),
            'payment_link' => self::sampleEmailUrl('/membership/payment', 'https://example.org/pay'),
            'migration_link' => self::sampleEmailUrl('/migrate.php?token=sample', 'https://example.org/migrate?token=sample'),
            'member_name' => 'Member',
            'membership_type' => 'Member',
            'renewal_date' => '2025-07-31',
            'order_number' => 'GW-12345',
            'payment_method' => 'stripe',
            'bank_transfer_instructions' => 'Bank: Australian Goldwing Association\nBSB: 123-456\nAccount: 987654',
            'rejection_reason' => 'Payment could not be verified.',
            'carrier' => 'Australia Post',
            'tracking_number' => 'TRACK123456',
            'address_html' => '<p><strong>Shipping address</strong><br>123 Goldwing Road<br>Canberra ACT 2600</p>',
            'items_html' => '<table style="width:100%; border-collapse:collapse; font-size:13px;"><tr><td>Sample item</td><td style="text-align:right;">$25.00</td></tr></table>',
            'totals_html' => '<p><strong>Total:</strong> $25.00</p>',
            'ticket_list_html' => '<p><strong>Tickets</strong><br>Goldwing Rally - ABC123</p>',
            'otp_code' => '482915',
            'expires_minutes' => '10',
            'refund_amount' => 'A$25.00',
            'refund_reason' => 'Customer requested.',
            'void_reason' => 'Internal correction.',
            'cancel_reason' => 'Out of stock.',
            'order_type_label' => 'membership',
        ];
        return $defaults;
    }

    private static function sampleEmailUrl(string $path, string $fallback): string
    {
        $link = BaseUrlService::emailLink($path);
        if ($link !== '') {
            return $link;
        }
        return $fallback;
    }

    public static function getAdminEmails(string $fallback = ''): array
    {
        $emails = self::normalizeEmails(SettingsService::getGlobal('notifications.admin_emails', ''));
        if (!$emails && $fallback !== '') {
            $emails = self::normalizeEmails($fallback);
        }
        if (!$emails) {
            $emails = self::normalizeEmails(SettingsService::getGlobal('site.contact_email', ''));
        }
        return $emails;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function resolveRecipients(array $settings, array $context, bool $allowPrimary): array
    {
        $mode = (string) ($settings['recipient_mode'] ?? 'primary');
        $primary = self::normalizeEmails($context['primary_email'] ?? '');
        $admin = self::normalizeEmails($context['admin_emails'] ?? '');
        $custom = self::normalizeEmails($settings['custom_recipients'] ?? '');
        switch ($mode) {
            case 'admin':
                return $admin;
            case 'both':
                return self::uniqueEmails(array_merge($allowPrimary ? $primary : [], $admin));
            case 'custom':
                return $custom;
            case 'primary':
            default:
                return $allowPrimary ? $primary : [];
        }
    }

    private static function shouldSendToMember(?int $memberId, string $category, bool $isMandatory, array $options, bool $force): bool
    {
        if ($isMandatory || $force || !empty($options['admin_override'])) {
            return true;
        }
        if (!self::isGloballyEnabled()) {
            return false;
        }
        return NotificationPreferenceService::shouldReceive($memberId, $category, $isMandatory, $force);
    }

    private static function resolveMemberId(array $context): ?int
    {
        if (!empty($context['member_id'])) {
            return (int) $context['member_id'];
        }
        if (!empty($context['user_id'])) {
            $memberId = self::lookupMemberIdByUserId((int) $context['user_id']);
            if ($memberId) {
                return $memberId;
            }
        }
        if (!empty($context['primary_email'])) {
            return self::lookupMemberIdByEmail((string) $context['primary_email']);
        }
        return null;
    }

    private static function lookupMemberIdByUserId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return !empty($row['member_id']) ? (int) $row['member_id'] : null;
    }

    private static function lookupMemberIdByEmail(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return !empty($row['member_id']) ? (int) $row['member_id'] : null;
    }

    private static function isGloballyEnabled(): bool
    {
        return SettingsService::getGlobal('notifications.enabled', true);
    }

    private static function resolveSender(array $settings): array
    {
        $fromName = trim((string) ($settings['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = SettingsService::getGlobal('notifications.from_name', 'Australian Goldwing Association');
        }
        $fromEmail = self::normalizeSenderEmail($settings['from_email'] ?? '');
        if ($fromEmail === '') {
            $fromEmail = self::normalizeSenderEmail(SettingsService::getGlobal('notifications.from_email', 'no-reply@goldwing.org.au'));
        }
        $replyTo = trim((string) ($settings['reply_to'] ?? ''));
        if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = SettingsService::getGlobal('notifications.reply_to', '');
        }
        return [
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to' => $replyTo,
        ];
    }

    private static function normalizeSenderEmail(string $value): string
    {
        $email = trim($value);
        if ($email === '') {
            return '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }

    private static function normalizeEmails($value): array
    {
        if (is_array($value)) {
            $emails = [];
            foreach ($value as $item) {
                $emails = array_merge($emails, self::normalizeEmails($item));
            }
            return self::uniqueEmails($emails);
        }
        $parts = preg_split('/[,\n;]+/', (string) $value);
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        return self::uniqueEmails($emails);
    }

    private static function uniqueEmails(array $emails): array
    {
        $emails = array_values(array_unique($emails));
        sort($emails);
        return $emails;
    }

    private static function applyTemplate(string $template, array $context): string
    {
        if ($template === '') {
            return '';
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = self::stringifyContextValue($value);
        }
        return strtr($template, $replacements);
    }

    /**
     * Normalize template context values so arrays render safely as text.
     */
    private static function stringifyContextValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::stringifyContextValue($item);
            }
            $parts = array_filter($parts, fn($item) => $item !== '');
            return implode(', ', $parts);
        }
        return (string) $value;
    }
}
