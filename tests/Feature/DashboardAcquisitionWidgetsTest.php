<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

class DashboardAcquisitionWidgetsTest extends TestCase
{
    public function test_dashboard_acquisition_tables_are_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Widgets/RecentAcquisitionsWidget.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Widgets/TopAcquiredProductsWidget.php'));
        $this->assertFileDoesNotExist(resource_path('views/filament/widgets/recent-acquisitions-widget.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/filament/widgets/top-acquired-products-widget.blade.php'));

        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertNotContains('App\\Filament\\Widgets\\RecentAcquisitionsWidget', $widgets);
        $this->assertNotContains('App\\Filament\\Widgets\\TopAcquiredProductsWidget', $widgets);
    }
}
