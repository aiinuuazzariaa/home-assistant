<?php

// menu sensor
$sensorMenus = [

    // state attributes
    'akar' => [
        'label'   => 'Akar (State Attributes)',
        'icon'    => 'leaf',
        'section' => 'State Attributes',
        'type'    => 'akar',
        'filter'  => '%Akar%',
        'columns' => ['ID', 'State Class', 'Unit of Measurement', 'Device Class', 'Friendly Name'],
    ],

    // sensor cuaca
    'sensor_suhu' => [
        'label'       => 'Sensor Suhu',
        'icon'        => 'thermometer',
        'section'     => 'Sensor Cuaca',
        'type'        => 'sensor',
        'filter'      => '%weather_station_suhu%',
        'round_state' => true,
        'join_meta'   => false,
        'columns'     => ['ID', 'Timestamp', 'State', 'Last Measured', 'State Class', 'Unit', 'Device Class', 'Friendly Name'],
    ],

    'sensor_kelembapan' => [
        'label'       => 'Sensor Kelembapan',
        'icon'        => 'droplet',
        'section'     => 'Sensor Cuaca',
        'type'        => 'sensor',
        'filter'      => '%weather_station_kelembapan%',
        'round_state' => false,
        'join_meta'   => false,
        'columns'     => ['ID', 'Timestamp', 'State', 'Last Measured', 'State Class', 'Unit', 'Device Class', 'Friendly Name'],
    ],

    'sensor_embun' => [
        'label'       => 'Sensor Titik Embun',
        'icon'        => 'cloud-drizzle',
        'section'     => 'Sensor Cuaca',
        'type'        => 'sensor',
        'filter'      => '%weather_station_dew_point%',
        'round_state' => true,
        'join_meta'   => true,
        'columns'     => ['ID', 'Timestamp', 'State', 'Last Measured', 'State Class', 'Unit', 'Device Class', 'Friendly Name'],
    ],

    'sensor_pressure' => [
        'label'       => 'Sensor Tekanan Udara',
        'icon'        => 'activity',
        'section'     => 'Sensor Cuaca',
        'type'        => 'sensor',
        'filter'      => '%weather_station_relative_pressure%',
        'round_state' => false,
        'join_meta'   => false,
        'columns'     => ['ID', 'Timestamp', 'State', 'Last Measured', 'State Class', 'Unit', 'Device Class', 'Friendly Name'],
    ],

    'sensor_uv' => [
        'label'       => 'Sensor UV Index',
        'icon'        => 'sun',
        'section'     => 'Sensor Cuaca',
        'type'        => 'sensor',
        'filter'      => '%weather_station_uv_index%',
        'round_state' => false,
        'join_meta'   => false,
        'columns'     => ['ID', 'Timestamp', 'State', 'Last Measured', 'State Class', 'Unit', 'Device Class', 'Friendly Name'],
    ],

    // kandungan tanah
    'kandungan_nitrogen' => [
        'label'   => 'Kandungan Nitrogen',
        'icon'    => 'flask',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field5',
        'unit'    => 'mg/kg',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_moisture' => [
        'label'   => 'Kandungan Moisture',
        'icon'    => 'droplets',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field2',
        'unit'    => '%',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_conductivity' => [
        'label'   => 'Kandungan Conductivity',
        'icon'    => 'zap',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field4',
        'unit'    => 'ms/cm',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_ph' => [
        'label'   => 'Kandungan pH',
        'icon'    => 'beaker',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field3',
        'unit'    => 'pH',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_phosphorus' => [
        'label'   => 'Kandungan Phosphorus',
        'icon'    => 'flask',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field6',
        'unit'    => 'mg/kg',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_potasium' => [
        'label'   => 'Kandungan Potasium',
        'icon'    => 'flask',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field7',
        'unit'    => 'mg/kg',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    'kandungan_temperature' => [
        'label'   => 'Suhu Tanah',
        'icon'    => 'thermometer',
        'section' => 'Kandungan Tanah',
        'type'    => 'kandungan',
        'filter'  => 'sensor.sensor_smartfarm',
        'field'   => 'field1',
        'unit'    => '°C',
        'columns' => ['ID', 'Timestamp', 'State', 'Unit', 'Friendly Name'],
    ],

    // event log
    'domain_sonoff' => [
        'label'   => 'Event Log Sonoff',
        'icon'    => 'radio',
        'section' => 'Event Log',
        'type'    => 'event',
        'filter'  => '%switch.sonoff%',
        'columns' => ['Hash', 'Event Type', 'Timestamp', 'Domain', 'Service', 'Entity ID'],
    ],

    'event_callservice' => [
        'label'   => 'Event Call Service',
        'icon'    => 'zap',
        'section' => 'Event Log',
        'type'    => 'event_callservice',
        'filter'  => 'call_service',
        'columns' => ['Hash', 'Event ID', 'Timestamp', 'Event Type', 'Domain', 'Service'],
    ],

    'event_change' => [
        'label'   => 'Event Change State',
        'icon'    => 'activity',
        'section' => 'Event Log',
        'type'    => 'event_change',
        'filter'  => 'call_service',
        'columns' => ['Hash', 'Action', 'Entity ID', 'Changes'],
    ],
];
