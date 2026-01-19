<?php $user = current_user(); ?>
<nav class="navbar">
  <div class="container nav-links">
    <div class="brand">Goldwing Admin</div>
    <?php if (current_admin_can('admin.dashboard.view', $user)): ?><a href="/admin/index.php">Dashboard</a><?php endif; ?>
    <?php if (current_admin_can('admin.members.view', $user)): ?><a href="/admin/members">Members</a><?php endif; ?>
    <?php if (current_admin_can('admin.members.view', $user)): ?><a href="/admin/index.php?page=applications">Applications</a><?php endif; ?>
    <?php if (current_admin_can('admin.payments.view', $user)): ?><a href="/admin/index.php?page=payments">Payments</a><?php endif; ?>
    <?php if (current_admin_can('admin.events.manage', $user)): ?><a href="/admin/index.php?page=events">Events</a><?php endif; ?>
    <?php if (current_admin_can('admin.pages.edit', $user)): ?><a href="/admin/navigation.php">Pages and Nav</a><?php endif; ?>
    <?php if (current_admin_can('admin.pages.edit', $user)): ?><a href="/admin/index.php?page=notices">Notices</a><?php endif; ?>
    <?php if (current_admin_can('admin.member_of_year.view', $user)): ?><a href="/admin/member-of-the-year">Member of the Year</a><?php endif; ?>
    <?php if (current_admin_can('admin.wings_magazine.manage', $user)): ?><a href="/admin/index.php?page=wings">Wings</a><?php endif; ?>
    <?php if (current_admin_can('admin.media_library.manage', $user)): ?><a href="/admin/index.php?page=media">Media</a><?php endif; ?>
    <?php if (current_admin_can('admin.ai_page_builder.access', $user)): ?><a href="/admin/page-builder">AI Page Builder</a><?php endif; ?>
    <?php if (current_admin_can('admin.logs.view', $user)): ?><a href="/admin/index.php?page=audit">Audit</a><?php endif; ?>
    <?php if (current_admin_can('admin.logs.view', $user)): ?><a href="/admin/index.php?page=reports">Reports</a><?php endif; ?>
    <a class="cta" href="/logout.php">Logout</a>
  </div>
</nav>
