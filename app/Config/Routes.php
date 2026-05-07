<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Disable auto-routing — all routes are explicit
$routes->setAutoRoute(false);
$routes->setDefaultNamespace('App\Controllers\Api');

// ── Health check ─────────────────────────────────────────────────────────────
$routes->get('/', 'HomeController::index');
$routes->options('(:any)', static function () {
    return response()->setStatusCode(204);
});

// ── v1 API group ──────────────────────────────────────────────────────────────
$routes->group('v1', static function (RouteCollection $routes): void {

    // ── Media proxy (public — no auth, serves S3 objects via presigned redirect) ─
    $routes->get('media', 'MediaController::serve');

    // ── App content / legal (public) ─────────────────────────────────────────
    $routes->get('app/legal/(:segment)', 'AppContentController::legal/$1');

    // ── Auth (public) ─────────────────────────────────────────────────────────
    $routes->group('auth', static function (RouteCollection $routes): void {
        $routes->post('login',    'Auth\AuthController::login');
        $routes->post('register', 'Auth\AuthController::register');
        $routes->post('refresh',  'Auth\AuthController::refresh');
        $routes->post('social',   'Auth\AuthController::social');

        // Protected auth routes
        $routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {
            $routes->get('me',          'Auth\AuthController::me');
            $routes->put('me',          'Auth\AuthController::updateMe');
            $routes->post('me/avatar',  'Auth\AuthController::uploadAvatar');
            $routes->post('me/cover',   'Auth\AuthController::uploadCover');
            $routes->post('logout',     'Auth\AuthController::logout');
            $routes->post('fcm-token',  'Auth\AuthController::registerFcmToken');
        });
    });

    // ── All remaining routes require auth ─────────────────────────────────────
    $routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {

        // ── Feed ──────────────────────────────────────────────────────────────
        $routes->get('feed', 'Feed\FeedController::index');

        // ── Listings ──────────────────────────────────────────────────────────
        $routes->get('listings/(:num)',           'Listings\ListingsController::show/$1');
        $routes->post('listings/(:num)/save',     'Listings\ListingsController::save/$1');
        $routes->post('listings/(:num)/rsvp',     'Listings\ListingsController::rsvp/$1');
        $routes->get('listings/(:num)/comments',  'Listings\ListingsController::comments/$1');
        $routes->post('listings/(:num)/comments', 'Listings\ListingsController::addComment/$1');
        $routes->post('listings/(:num)/like',     'Listings\ListingsController::like/$1');
        $routes->post('listings/(:num)/share',    'Listings\ListingsController::share/$1');
        $routes->post('listings/(:num)/apply',    'Listings\ListingsController::apply/$1');
        $routes->post('listings/(:num)/report',   'Listings\ListingsController::report/$1');

        // ── Discover ──────────────────────────────────────────────────────────
        $routes->get('discover',                  'Discover\DiscoverController::index');
        $routes->post('discover/(:num)/pass',     'Discover\DiscoverController::pass/$1');

        // ── Search ────────────────────────────────────────────────────────────
        $routes->get('search/filters', 'Search\SearchController::filters');
        $routes->get('search',         'Search\SearchController::index');

        // ── Categories ────────────────────────────────────────────────────────
        $routes->get('categories', 'Categories\CategoriesController::index');

        // ── Live (specific routes before wildcard :id) ────────────────────────
        $routes->get('live',                      'Live\LiveController::index');
        $routes->post('live/start',               'Live\LiveController::start');
        $routes->get('live/(:num)/replay',        'Live\LiveController::replay/$1');
        $routes->post('live/(:num)/join',         'Live\LiveController::join/$1');
        $routes->post('live/(:num)/end',          'Live\LiveController::end/$1');
        $routes->post('live/(:num)/cohost',       'Live\LiveController::addCohost/$1');
        $routes->post('live/(:num)/cohost-join',  'Live\LiveController::cohostJoin/$1');
        $routes->delete('live/(:num)/cohost',     'Live\LiveController::removeCohost/$1');
        $routes->get('live/(:num)/comments',      'Live\LiveController::getComments/$1');
        $routes->post('live/(:num)/comment',      'Live\LiveController::comment/$1');
        $routes->post('live/(:num)/react',        'Live\LiveController::react/$1');
        $routes->post('live/(:num)/report',       'Live\LiveController::reportSession/$1');
        $routes->get('live/(:num)',               'Live\LiveController::show/$1');
        $routes->put('live/(:num)',               'Live\LiveController::update/$1');

        // ── Posts ─────────────────────────────────────────────────────────────
        $routes->get('posts',                   'Posts\PostsController::index');
        $routes->post('posts',                  'Posts\PostsController::create');
        $routes->post('posts/(:num)/like',      'Posts\PostsController::like/$1');
        $routes->get('posts/(:num)/comments',   'Posts\PostsController::comments/$1');
        $routes->post('posts/(:num)/comments',  'Posts\PostsController::addComment/$1');
        $routes->get('users/(:num)/posts',      'Posts\PostsController::byUser/$1');
        $routes->get('users/(:num)/chapters',   'Profile\ProfileTabsController::userChapters/$1');

        // ── Profile tabs ──────────────────────────────────────────────────────
        $routes->get('profile/posts',    'Profile\ProfileTabsController::posts');
        $routes->get('profile/saved',    'Profile\ProfileTabsController::saved');
        $routes->get('profile/chapters', 'Profile\ProfileTabsController::chapters');

        // ── Submissions ───────────────────────────────────────────────────────
        $routes->post('submissions',     'Submissions\SubmissionsController::create');
        $routes->get('submissions',      'Submissions\SubmissionsController::mySubmissions');
        $routes->get('submissions/(:num)', 'Submissions\SubmissionsController::show/$1');

        // ── Activity ──────────────────────────────────────────────────────────
        $routes->get('activity', 'Activity\ActivityController::index');

        // ── Notifications ─────────────────────────────────────────────────────
        $routes->get('notifications',                     'Notifications\NotificationsController::index');
        $routes->put('notifications/(:num)/read',         'Notifications\NotificationsController::markRead/$1');
        $routes->put('notifications/read-all',            'Notifications\NotificationsController::markAllRead');

        // ── Onboarding ────────────────────────────────────────────────────────
        $routes->get('interests',                        'Onboarding\OnboardingController::interests');
        $routes->post('onboarding/interests',            'Onboarding\OnboardingController::saveInterests');
        $routes->post('onboarding/location',             'Onboarding\OnboardingController::saveLocation');
        $routes->post('onboarding/notifications',        'Onboarding\OnboardingController::saveNotificationPreferences');

        // ── Profile / Users ───────────────────────────────────────────────────
        $routes->get('users/search',                   'Chat\ChatController::searchUsers');
        $routes->get('users/(:num)',                    'Profile\ProfileController::show/$1');
        $routes->get('users/(:num)/connection-status', 'Connections\ConnectionsController::connectionStatus/$1');
        $routes->post('users/(:num)/block',            'Profile\ProfileController::block/$1');
        $routes->get('users/(:num)/follow-status',    'Profile\FollowController::status/$1');
        $routes->post('users/(:num)/follow',          'Profile\FollowController::follow/$1');
        $routes->delete('users/(:num)/follow',        'Profile\FollowController::unfollow/$1');

        // ── Chat — File upload ────────────────────────────────────────────────
        $routes->post('chat/upload',                             'Chat\ChatController::uploadFile');

        // ── Chat — Conversations (specific sub-routes before wildcard :id) ──────
        $routes->get('chat/conversations',                       'Chat\ChatController::conversations');
        $routes->post('chat/conversations',                      'Chat\ChatController::startDm');
        $routes->get('chat/conversations/(:num)',                  'Chat\ChatController::show/$1');
        $routes->get('chat/conversations/(:num)/messages',       'Chat\ChatController::messages/$1');
        $routes->post('chat/conversations/(:num)/messages',      'Chat\ChatController::sendMessage/$1');
        $routes->get('chat/conversations/(:num)/media',          'Chat\ChatController::media/$1');
        $routes->put('chat/conversations/(:num)/mute',           'Chat\ChatController::muteConversation/$1');
        $routes->put('chat/conversations/(:num)/read',           'Chat\ChatController::markRead/$1');
        $routes->post('chat/conversations/(:num)/report',        'Chat\ChatController::reportConversation/$1');
        $routes->post('chat/conversations/(:num)/block',         'Chat\ChatController::blockFromConversation/$1');

        // ── Chat — Messages ───────────────────────────────────────────────────
        $routes->delete('chat/messages/(:num)',                  'Chat\ChatController::deleteMessage/$1');
        $routes->post('chat/messages/(:num)/react',              'Chat\ChatController::reactToMessage/$1');

        // ── Chat — Groups ─────────────────────────────────────────────────────
        $routes->post('chat/group',                              'Chat\GroupController::create');
        $routes->put('chat/group/(:num)',                        'Chat\GroupController::update/$1');
        $routes->post('chat/group/(:num)/members',               'Chat\GroupController::addMembers/$1');
        $routes->delete('chat/group/(:num)/members/(:num)',      'Chat\GroupController::removeMember/$1/$2');
        $routes->delete('chat/group/(:num)/leave',               'Chat\GroupController::leave/$1');

        // ── Connections / Requests ────────────────────────────────────────────
        $routes->get('chat/requests',                            'Connections\ConnectionsController::index');
        $routes->post('chat/requests',                           'Connections\ConnectionsController::send');
        $routes->put('chat/requests/(:num)/accept',              'Connections\ConnectionsController::accept/$1');
        $routes->put('chat/requests/(:num)/decline',             'Connections\ConnectionsController::decline/$1');

        // ── Chapters (BlackWins) ──────────────────────────────────────────────
        $routes->get('chapters',                     'Chapters\ChaptersController::index');
        $routes->post('chapters',                    'Chapters\ChaptersController::create');
        $routes->get('chapters/(:num)',              'Chapters\ChaptersController::show/$1');
        $routes->post('chapters/(:num)/join',        'Chapters\ChaptersController::join/$1');
        $routes->get('chapters/(:num)/feed',         'Chapters\ChaptersController::feed/$1');

        // ── Chapter group chat (messages) ─────────────────────────────────────
        $routes->get('chapters/(:num)/messages',                    'Chapters\ChapterMessagesController::index/$1');
        $routes->post('chapters/(:num)/messages',                   'Chapters\ChapterMessagesController::create/$1');
        $routes->delete('chapters/(:num)/messages/(:num)',          'Chapters\ChapterMessagesController::delete/$1/$2');
        $routes->post('chapters/(:num)/messages/(:num)/react',      'Chapters\ChapterMessagesController::react/$1/$2');

        // ── Black Census ──────────────────────────────────────────────────────
        $routes->post('census',                      'Census\CensusController::submit');

        // ── Marketplace ───────────────────────────────────────────────────────
        $routes->get('marketplace',                  'Marketplace\MarketplaceController::index');
        $routes->get('marketplace/categories',       'Marketplace\MarketplaceController::categories');

        // ── Storefront Activation (platform Stripe fee) ───────────────────────
        $routes->post('marketplace/activation/create-session', 'Marketplace\ActivationController::createSession');
        $routes->get('marketplace/activation/status',          'Marketplace\ActivationController::status');

        // ── Vendor payment gateway sessions (vendor → customer) ───────────────
        $routes->post('marketplace/orders/(:num)/pay',            'Marketplace\PaymentController::createOrderPayment/$1');
        $routes->post('marketplace/orders/(:num)/confirm-payment', 'Marketplace\PaymentController::confirmPayment/$1');
        $routes->get('marketplace/vendors/(:num)/gateways',       'Marketplace\PaymentController::vendorGateways/$1');


        // ── Vendors ───────────────────────────────────────────────────────────
        $routes->get('vendors',                                  'Marketplace\VendorsController::index');
        $routes->post('vendors',                                 'Marketplace\VendorsController::create');
        $routes->get('vendors/my/payment-settings',              'Marketplace\VendorsController::getPaymentSettings');
        $routes->get('vendors/my',                               'Marketplace\VendorsController::myStore');
        $routes->get('vendors/(:num)',               'Marketplace\VendorsController::show/$1');
        $routes->put('vendors/(:num)',               'Marketplace\VendorsController::update/$1');
        $routes->post('vendors/(:num)',              'Marketplace\VendorsController::update/$1');
        $routes->put('vendors/(:num)/payment-settings', 'Marketplace\VendorsController::paymentSettings/$1');
        $routes->post('vendors/(:num)/payment-settings', 'Marketplace\VendorsController::paymentSettings/$1');
        $routes->post('vendors/(:num)/products',     'Marketplace\ProductsController::create/$1');

        // ── Admin vendor management ───────────────────────────────────────────
        $routes->get('admin/vendors',                    'Admin\VendorsAdminController::index');
        $routes->put('admin/vendors/(:num)/approve',     'Admin\VendorsAdminController::approve/$1');
        $routes->put('admin/vendors/(:num)/reject',      'Admin\VendorsAdminController::reject/$1');
        $routes->get('admin/platform-settings',          'Admin\VendorsAdminController::platformSettings');
        $routes->put('admin/platform-settings',          'Admin\VendorsAdminController::platformSettings');
        $routes->get('admin/vendors/revenue',            'Admin\VendorsAdminController::revenue');

        // ── Products ──────────────────────────────────────────────────────────
        $routes->get('products/(:num)',              'Marketplace\ProductsController::show/$1');
        $routes->put('products/(:num)',              'Marketplace\ProductsController::update/$1');
        $routes->delete('products/(:num)',           'Marketplace\ProductsController::delete/$1');

        // ── Cart & Orders ─────────────────────────────────────────────────────
        $routes->get('cart',                         'Marketplace\OrdersController::cart');
        $routes->post('cart',                        'Marketplace\OrdersController::addToCart');
        $routes->put('cart/(:num)',                  'Marketplace\OrdersController::updateCart/$1');
        $routes->delete('cart/(:num)',               'Marketplace\OrdersController::removeFromCart/$1');
        $routes->post('orders/checkout',             'Marketplace\OrdersController::checkout');
        $routes->get('orders',                       'Marketplace\OrdersController::myOrders');
        $routes->put('orders/(:num)/status',         'Marketplace\OrdersController::updateStatus/$1');
    });

    // ── No-auth: payment gateway redirects & webhooks (no Bearer token) ─────────
    $routes->get('marketplace/flutterwave-checkout',        'Marketplace\PaymentController::flutterwaveCheckout');
    $routes->get( 'marketplace/activation/paid',            'Marketplace\ActivationController::paid');
    $routes->get( 'marketplace/activation/cancel',          'Marketplace\ActivationController::paid');
    $routes->post('marketplace/activation/webhook',         'Marketplace\ActivationController::webhook');
    $routes->post('marketplace/webhooks/stripe',            'Marketplace\PaymentController::stripeWebhook');
    $routes->post('marketplace/webhooks/paypal',            'Marketplace\PaymentController::paypalWebhook');
    $routes->post('marketplace/webhooks/flutterwave',       'Marketplace\PaymentController::flutterwaveWebhook');
    $routes->get('marketplace/orders/(:num)/payment-success', 'Marketplace\OrdersController::paymentSuccess/$1');
    $routes->get('marketplace/orders/(:num)/payment-cancel',  'Marketplace\OrdersController::paymentCancel/$1');
});

// ── Admin Manager (session-based, no JWT) ─────────────────────────────────────
$routes->setDefaultNamespace('App\Controllers\Admin');

// Public: login page (excluded from adminauth filter via AdminAuthFilter logic)
$routes->get( 'manager/login',  'Auth\AdminAuthController::loginForm');
$routes->post('manager/login',  'Auth\AdminAuthController::login');
$routes->get( 'manager/logout', 'Auth\AdminAuthController::logout');

$routes->group('manager', ['filter' => 'adminauth'], static function (RouteCollection $routes): void {

    // Dashboard
    $routes->get('', 'DashboardController::index');

    // Users
    $routes->get( 'users',                          'UsersController::index');
    $routes->get( 'users/(:num)',                   'UsersController::show/$1');
    $routes->post('users/(:num)/toggle-status',     'UsersController::toggleStatus/$1');
    $routes->post('users/(:num)/delete',            'UsersController::delete/$1');

    // Listings
    $routes->get( 'listings',                       'ListingsController::index');
    $routes->get( 'listings/create',                'ListingsController::create');
    $routes->post('listings/create',                'ListingsController::store');
    $routes->get( 'listings/(:num)/edit',           'ListingsController::edit/$1');
    $routes->post('listings/(:num)/edit',           'ListingsController::update/$1');
    $routes->get( 'listings/(:num)',                'ListingsController::show/$1');
    $routes->post('listings/(:num)/toggle-status',  'ListingsController::toggleStatus/$1');
    $routes->post('listings/(:num)/trust',          'ListingsController::updateTrust/$1');
    $routes->post('listings/(:num)/delete',         'ListingsController::delete/$1');

    // Moderation
    $routes->get( 'moderation',                                     'ModerationController::index');
    $routes->post('moderation/submissions/(:num)/approve',          'ModerationController::approveSubmission/$1');
    $routes->post('moderation/submissions/(:num)/reject',           'ModerationController::rejectSubmission/$1');
    $routes->post('moderation/reports/(:num)/resolve',              'ModerationController::resolveReport/$1');

    // Live
    $routes->get( 'live',               'LiveController::index');
    $routes->post('live/(:num)/end',    'LiveController::endSession/$1');
    $routes->post('live/(:num)/delete', 'LiveController::delete/$1');

    // Chat
    $routes->get( 'chat',                                   'ChatController::index');
    $routes->get( 'chat/(:num)',                            'ChatController::viewConversation/$1');
    $routes->post('chat/(:num)/member/(:num)/remove',       'ChatController::removeMember/$1/$2');
    $routes->get( 'chat/(:num)/delete',                     'ChatController::deleteConversation/$1');
    $routes->post('chat/(:num)/warn/(:num)',                 'ChatController::warnUser/$1/$2');

    // Notifications
    $routes->get( 'notifications',      'NotificationsController::index');
    $routes->post('notifications/send', 'NotificationsController::send');

    // Analytics
    $routes->get('analytics', 'AnalyticsController::index');

    // Settings
    $routes->get( 'settings',                   'SettingsController::index');
    $routes->post('settings/category/save',     'SettingsController::saveCategory');
    $routes->post('settings/jwt/rotate',        'SettingsController::rotateJwt');
    $routes->get( 'settings/env',               'SettingsController::envVars');

    // Marketplace management
    $routes->get( 'marketplace',                              'MarketplaceController::index');
    $routes->get( 'marketplace/payment-settings',             'MarketplaceController::paymentSettings');
    $routes->post('marketplace/payment-settings/save',        'MarketplaceController::savePaymentSettings');
    $routes->get( 'marketplace/vendors/(:num)',               'MarketplaceController::showVendor/$1');
    $routes->post('marketplace/vendors/(:num)/toggle',        'MarketplaceController::toggleVendor/$1');
    $routes->post('marketplace/vendors/(:num)/approve',       'MarketplaceController::approveVendor/$1');
    $routes->post('marketplace/vendors/(:num)/reject',        'MarketplaceController::rejectVendor/$1');
    $routes->get( 'marketplace/vendors/(:num)/test-payment',  'MarketplaceController::testActivationPayment/$1');
    $routes->get( 'marketplace/products',                     'MarketplaceController::products');
    $routes->post('marketplace/products/(:num)/toggle',       'MarketplaceController::toggleProduct/$1');
    $routes->get( 'marketplace/orders',                       'MarketplaceController::orders');
    $routes->post('marketplace/orders/(:num)/status',         'MarketplaceController::updateOrderStatus/$1');

    // Black Census submissions
    $routes->get( 'census',                                   'CensusController::index');
    $routes->get( 'census/export',                            'CensusController::export');

    // App Content / Legal pages (admin editable)
    $routes->get( 'app-content',                              'AppContentController::index');
    $routes->get( 'app-content/(:segment)/edit',              'AppContentController::edit/$1');
    $routes->post('app-content/(:segment)/save',              'AppContentController::save/$1');

    // Admin user management (super_admin only)
    $routes->get( 'admin-users',                              'AdminUsersController::index');
    $routes->get( 'admin-users/create',                       'AdminUsersController::create');
    $routes->post('admin-users/create',                       'AdminUsersController::store');
    $routes->post('admin-users/(:num)/toggle',                'AdminUsersController::toggleStatus/$1');
    $routes->post('admin-users/(:num)/delete',                'AdminUsersController::delete/$1');
});
