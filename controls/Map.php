<?php
/**
 * Map Controller
 * Displays interactive maps
 */

namespace app;

use \Flight as Flight;

class Map extends BaseControls\Control {

    /**
     * USA Map page
     * Displays an interactive map of the United States
     */
    public function usa() {
        $this->render('map/usa', [
            'title' => 'Map of the USA',
            'states' => $this->getStatesData()
        ]);
    }

    /**
     * Get state details (AJAX endpoint)
     */
    public function stateDetails() {
        $stateCode = $this->getParam('state', '');

        if (empty($stateCode)) {
            $this->jsonError('State code required', 400);
            return;
        }

        $states = $this->getStatesData();
        $stateCode = strtoupper($stateCode);

        if (!isset($states[$stateCode])) {
            $this->jsonError('State not found', 404);
            return;
        }

        $state = $states[$stateCode];

        $this->json([
            'success' => true,
            'state' => $state
        ]);
    }

    /**
     * Get data for all US states
     */
    private function getStatesData(): array {
        return [
            'AL' => ['name' => 'Alabama', 'code' => 'AL', 'capital' => 'Montgomery'],
            'AK' => ['name' => 'Alaska', 'code' => 'AK', 'capital' => 'Juneau'],
            'AZ' => ['name' => 'Arizona', 'code' => 'AZ', 'capital' => 'Phoenix'],
            'AR' => ['name' => 'Arkansas', 'code' => 'AR', 'capital' => 'Little Rock'],
            'CA' => ['name' => 'California', 'code' => 'CA', 'capital' => 'Sacramento'],
            'CO' => ['name' => 'Colorado', 'code' => 'CO', 'capital' => 'Denver'],
            'CT' => ['name' => 'Connecticut', 'code' => 'CT', 'capital' => 'Hartford'],
            'DE' => ['name' => 'Delaware', 'code' => 'DE', 'capital' => 'Dover'],
            'FL' => ['name' => 'Florida', 'code' => 'FL', 'capital' => 'Tallahassee'],
            'GA' => ['name' => 'Georgia', 'code' => 'GA', 'capital' => 'Atlanta'],
            'HI' => ['name' => 'Hawaii', 'code' => 'HI', 'capital' => 'Honolulu'],
            'ID' => ['name' => 'Idaho', 'code' => 'ID', 'capital' => 'Boise'],
            'IL' => ['name' => 'Illinois', 'code' => 'IL', 'capital' => 'Springfield'],
            'IN' => ['name' => 'Indiana', 'code' => 'IN', 'capital' => 'Indianapolis'],
            'IA' => ['name' => 'Iowa', 'code' => 'IA', 'capital' => 'Des Moines'],
            'KS' => ['name' => 'Kansas', 'code' => 'KS', 'capital' => 'Topeka'],
            'KY' => ['name' => 'Kentucky', 'code' => 'KY', 'capital' => 'Frankfort'],
            'LA' => ['name' => 'Louisiana', 'code' => 'LA', 'capital' => 'Baton Rouge'],
            'ME' => ['name' => 'Maine', 'code' => 'ME', 'capital' => 'Augusta'],
            'MD' => ['name' => 'Maryland', 'code' => 'MD', 'capital' => 'Annapolis'],
            'MA' => ['name' => 'Massachusetts', 'code' => 'MA', 'capital' => 'Boston'],
            'MI' => ['name' => 'Michigan', 'code' => 'MI', 'capital' => 'Lansing'],
            'MN' => ['name' => 'Minnesota', 'code' => 'MN', 'capital' => 'Saint Paul'],
            'MS' => ['name' => 'Mississippi', 'code' => 'MS', 'capital' => 'Jackson'],
            'MO' => ['name' => 'Missouri', 'code' => 'MO', 'capital' => 'Jefferson City'],
            'MT' => ['name' => 'Montana', 'code' => 'MT', 'capital' => 'Helena'],
            'NE' => ['name' => 'Nebraska', 'code' => 'NE', 'capital' => 'Lincoln'],
            'NV' => ['name' => 'Nevada', 'code' => 'NV', 'capital' => 'Carson City'],
            'NH' => ['name' => 'New Hampshire', 'code' => 'NH', 'capital' => 'Concord'],
            'NJ' => ['name' => 'New Jersey', 'code' => 'NJ', 'capital' => 'Trenton'],
            'NM' => ['name' => 'New Mexico', 'code' => 'NM', 'capital' => 'Santa Fe'],
            'NY' => ['name' => 'New York', 'code' => 'NY', 'capital' => 'Albany'],
            'NC' => ['name' => 'North Carolina', 'code' => 'NC', 'capital' => 'Raleigh'],
            'ND' => ['name' => 'North Dakota', 'code' => 'ND', 'capital' => 'Bismarck'],
            'OH' => ['name' => 'Ohio', 'code' => 'OH', 'capital' => 'Columbus'],
            'OK' => ['name' => 'Oklahoma', 'code' => 'OK', 'capital' => 'Oklahoma City'],
            'OR' => ['name' => 'Oregon', 'code' => 'OR', 'capital' => 'Salem'],
            'PA' => ['name' => 'Pennsylvania', 'code' => 'PA', 'capital' => 'Harrisburg'],
            'RI' => ['name' => 'Rhode Island', 'code' => 'RI', 'capital' => 'Providence'],
            'SC' => ['name' => 'South Carolina', 'code' => 'SC', 'capital' => 'Columbia'],
            'SD' => ['name' => 'South Dakota', 'code' => 'SD', 'capital' => 'Pierre'],
            'TN' => ['name' => 'Tennessee', 'code' => 'TN', 'capital' => 'Nashville'],
            'TX' => ['name' => 'Texas', 'code' => 'TX', 'capital' => 'Austin'],
            'UT' => ['name' => 'Utah', 'code' => 'UT', 'capital' => 'Salt Lake City'],
            'VT' => ['name' => 'Vermont', 'code' => 'VT', 'capital' => 'Montpelier'],
            'VA' => ['name' => 'Virginia', 'code' => 'VA', 'capital' => 'Richmond'],
            'WA' => ['name' => 'Washington', 'code' => 'WA', 'capital' => 'Olympia'],
            'WV' => ['name' => 'West Virginia', 'code' => 'WV', 'capital' => 'Charleston'],
            'WI' => ['name' => 'Wisconsin', 'code' => 'WI', 'capital' => 'Madison'],
            'WY' => ['name' => 'Wyoming', 'code' => 'WY', 'capital' => 'Cheyenne'],
            'DC' => ['name' => 'District of Columbia', 'code' => 'DC', 'capital' => 'Washington']
        ];
    }
}
