<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

enum Permission: string
{
    case CatalogueView = 'catalogue.view';
    case CatalogueCreate = 'catalogue.create';
    case CatalogueEditOwn = 'catalogue.edit_own';
    case CatalogueEditAny = 'catalogue.edit_any';
    case CatalogueDelete = 'catalogue.delete';
    case CopyManageOwn = 'copy.manage_own';
    case CopyManageAny = 'copy.manage_any';
    case LocationManage = 'location.manage';
    case CategoryManage = 'category.manage';
    case UserManage = 'user.manage';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
    case LoanManageOwn = 'loan.manage_own';
    case LoanManageAny = 'loan.manage_any';
    case WishlistManageOwn = 'wishlist.manage_own';
    case CollectionManageOwn = 'collection.manage_own';
    case CollectionManageAny = 'collection.manage_any';
    case InvitationManage = 'invitation.manage';
    case CatalogueExport = 'catalogue.export';
}
