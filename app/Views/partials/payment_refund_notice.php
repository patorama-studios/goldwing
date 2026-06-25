<?php
/**
 * Shared payment / refund information notice.
 *
 * Rendered on every customer-facing payment form (membership join, store
 * checkout, membership renewal). Uses inline styles on purpose so it renders
 * consistently on both the custom .form-* CSS used by apply.php and the
 * Tailwind pages (checkout.php, member portal).
 *
 * ponytail: copy is hardcoded policy text (changes ~never). If the committee
 * later wants to edit the wording without a deploy, move it to a
 * payments.refund_notice setting, mirroring payments.bank_transfer_instructions.
 *
 * Set $refundNoticeOmitBankLine = true before requiring this on a form that does
 * not actually offer bank transfer (e.g. the card-only renewal drawer), so we
 * don't tell members to use an option that isn't on screen.
 */
$refundNoticeOmitBankLine = !empty($refundNoticeOmitBankLine);
?>
<div style="margin:12px 0;padding:12px 14px;border:1px solid #fcd9b6;background:#fff7ed;border-radius:10px;font-size:13px;line-height:1.5;color:#7c4a13;">
  <strong style="display:block;margin-bottom:4px;color:#7c2d12;">Payment &amp; refund information</strong>
  Card payments are processed securely by Stripe. Any card-processing fee shown at checkout
  is charged by Stripe and is <strong>non-refundable</strong> &mdash; if a refund is requested,
  only the actual cost of your purchase (membership fees or merchandise) will be refunded.
  <?php if (!$refundNoticeOmitBankLine): ?>
  Paying by <strong>direct deposit / bank transfer incurs no additional fees</strong>.
  <?php endif; ?>
</div>
<?php $refundNoticeOmitBankLine = false; // reset so a later include on the same page gets the full notice ?>
