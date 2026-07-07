<x-filament-panels::page>
    <div class="ak-reports">
        @foreach ($this->getReportGroups() as $group => $reports)
            <section class="ak-report-group" aria-label="{{ $group }}">
                <h2>{{ $group }}</h2>
                <div class="ak-report-grid">
                    @foreach ($reports as $report)
                        <a class="ak-report-card" href="{{ $report['url'] }}">
                            <span class="ak-report-icon" aria-hidden="true">
                                <x-filament::icon :icon="$report['icon']" />
                            </span>
                            <span class="ak-report-body">
                                <strong>{{ $report['title'] }}</strong>
                                <span>{{ $report['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
