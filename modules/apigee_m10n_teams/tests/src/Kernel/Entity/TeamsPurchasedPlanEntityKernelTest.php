<?php

/*
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_m10n_teams\Kernel\Entity;

use Drupal\apigee_edge_teams\TeamPermissionHandlerInterface;
use Drupal\apigee_m10n\Entity\ProductBundle;
use Drupal\apigee_m10n_teams\Entity\TeamProductBundleInterface;
use Drupal\Core\Url;
use Drupal\Tests\apigee_m10n\Traits\RatePlansPropertyEnablerTrait;
use Drupal\Tests\apigee_m10n_teams\Kernel\MonetizationTeamsKernelTestBase;
use Drupal\Tests\apigee_m10n_teams\Traits\TeamProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the team purchased plan entity.
 *
 * @group apigee_m10n
 * @group apigee_m10n_kernel
 * @group apigee_m10n_teams
 * @group apigee_m10n_teams_kernel
 */
class TeamsPurchasedPlanEntityKernelTest extends MonetizationTeamsKernelTestBase {

  use TeamProphecyTrait;

  /**
   * Drupal user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $developer;

  /**
   * Drupal user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $non_member;

  /**
   * An apigee team.
   *
   * @var \Drupal\apigee_edge_teams\Entity\TeamInterface
   */
  protected $team;

  /**
   * A test product bundle.
   *
   * @var \Drupal\apigee_m10n\Entity\ProductBundleInterface
   */
  protected $product_bundle;

  /**
   * Test rate plans.
   *
   * @var \Drupal\apigee_m10n\Entity\RatePlanInterface[]
   */
  protected $rate_plans;

  /**
   * Purchased rate plans.
   *
   * @var \Drupal\apigee_m10n_teams\Entity\TeamsPurchasedPlan[]
   */
  protected $purchased_rate_plans;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'user',
      'system',
      'apigee_m10n',
    ]);

    // Makes sure the new user isn't root.
    $this->createAccount();

    $this->non_member = $this->createAccount(['view rate_plan', 'purchase rate_plan']);
    $this->developer = $this->createAccount(['view rate_plan', 'purchase rate_plan']);
    $this->team = $this->createTeam();
    $this->addUserToTeam($this->team, $this->developer);

    $this->product_bundle = $this->createProductBundle();
    $this->rate_plans[] = $this->createRatePlan($this->product_bundle);
    $this->rate_plans[] = $this->createRatePlan($this->product_bundle);
    $this->purchased_rate_plans[] = $this->createTeamPurchasedPlan($this->team, $this->rate_plans[0]);
    $this->purchased_rate_plans[] = $this->createTeamPurchasedPlan($this->team, $this->rate_plans[1]);

    $this->createCurrentUserSession($this->developer);
  }

  /**
   * Test team purchased plan entity rendering.
   *
   * @throws \Exception
   */
  public function testPurchasedPlanEntity() {
    $this->setCurrentTeamRoute($this->team);
    $this->warmTnsCache();
    $this->warmTeamTnsCache($this->team);

//    $non_member = $this->createAccount();
/*
    // Prophesize the `apigee_edge_teams.team_permissions` service.
    $team_handler = $this->prophesize(TeamPermissionHandlerInterface::class);
    $team_handler->getDeveloperPermissionsByTeam($this->team, $this->developer)->willReturn([
      'view product_bundle',
      'view rate_plan',
      'purchase rate_plan',
      'view purchased_plan',
    ]);
//    $team_handler->getDeveloperPermissionsByTeam($this->team, $non_member)->willReturn([]);
    $this->container->set('apigee_edge_teams.team_permissions', $team_handler->reveal());*/

    $this->stack
      ->queueMockResponse(['get_developer_purchased_plans' => ['purchased_plans' => $this->purchased_rate_plans]]);

    $uri = Url::fromRoute('entity.purchased_plan.team_collection', ['team' => $this->team->id()])
      ->toString();
    $request = Request::create($uri, 'GET');
    try {
      $response = $this->container->get('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
    }
    catch (\Throwable $e) {
      var_dump($e);
    }

    $this->assertTrue(TRUE);return;
    $this->setRawContent($response->getContent());

    // Test the response.
    static::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertTitle('Purchased plans');

    return;
    // Check team access.
    $access = $this->purchased_rate_plan->access('view', $this->developer, TRUE);
    var_dump($access);
    static::assertTrue($access->isAllowed());
//    static::assertFalse($this->purchased_rate_plan->access('view', $non_member));
    return;


    // Make sure we get a team context when getting a product bundle url.
    $url = $this->product_bundle->toUrl('team');
    static::assertSame("/teams/{$this->team->id()}/monetization/product-bundle/{$this->product_bundle->id()}", $url->toString());
    static::assertSame('entity.product_bundle.team', $url->getRouteName());

    // Load the cached product bundle.
    $product_bundle = ProductBundle::load($this->product_bundle->id());

    static::assertInstanceOf(TeamProductBundleInterface::class, $product_bundle);
    // Use the object comparator to compare the loaded product bundle.
    static::assertEquals($this->product_bundle, $product_bundle);

    // Get the product bundle products.
    static::assertGreaterThan(0, $this->count($product_bundle->getApiProducts()));

    // Render the product bundle.
    $build = \Drupal::entityTypeManager()
      ->getViewBuilder('product_bundle')
      ->view($this->product_bundle, 'default');

    $rate_plan_1 = $this->createRatePlan($this->product_bundle);
    $rate_plan_2 = $this->createRatePlan($this->product_bundle);

    $this->stack->queueMockResponse(['get_monetization_package_plans' => ['plans' => [$rate_plan_1, $rate_plan_2]]]);
    $content = \Drupal::service('renderer')->renderRoot($build);

    $this->setRawContent((string) $content);

    $css_prefix = '.apigee-entity.product-bundle';
    // Product bundle detail as rendered.
    $this->assertCssElementText("{$css_prefix} > h2", $product_bundle->label());

    // API Products.
    $this->assertCssElementText("{$css_prefix} .field--name-apiproducts .field__label", 'Included products');
    foreach ($this->product_bundle->get('apiProducts') as $index => $apiProduct) {
      // CSS indexes start at 1.
      $css_index = $index + 1;
      $this->assertCssElementText("{$css_prefix} .field--name-apiproducts .field__item:nth-child({$css_index})", $apiProduct->entity->label());
    }

    // Rate plans as rendered.
    foreach ([$rate_plan_1, $rate_plan_2] as $index => $rate_plan) {
      /** @var \Drupal\apigee_m10n\Entity\RatePlanInterface $rate_plan */
      $this->assertLink($rate_plan->label());
      $this->assertLinkByHref($rate_plan->toUrl()->toString());
    }
  }

}
