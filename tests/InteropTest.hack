namespace Avro\Tests\Interop;

use function \Avro\{Marshal, Unmarshal, ParseSchema};
use type \Avro\{Schema, AvroException};
use function \Avro\Container\{WriteFile, ReadFile};

<<__EntryPoint>>
function main(): void {
  require_once __DIR__.'/_bootstrap.hack';
  \Avro\Tests\bootstrap();

  echo "=== Interop Tests ===\n\n";

  test_read_java_weather_avro();
  test_read_simple_avro();
  test_write_read_container_roundtrip();
  test_interop_schema_roundtrip();

  echo "\n=== ALL INTEROP TESTS PASSED ===\n";
}

function assert_eq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new \Exception($msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true));
  }
}

function test_read_java_weather_avro(): void {
  echo "Testing read Java-generated weather.avro: ";

  $data = \file_get_contents(__DIR__.'/weather.avro');
  $reader = ReadFile($data);

  $schema = $reader->getSchema();
  assert_eq($schema->name, 'test.Weather', 'schema name');
  assert_eq($reader->getCodec(), 'null', 'codec');

  $records = $reader->readAll();
  if (\count($records) === 0) {
    throw new \Exception("expected at least one record");
  }

  $r0 = $records[0];
  if (!($r0 is dict<_, _>)) {
    throw new \Exception("expected dict record");
  }

  if (!\array_key_exists('station', $r0) || !\array_key_exists('temp', $r0)) {
    throw new \Exception("expected station and temp fields, got: ".\print_r($r0, true));
  }

  echo "PASSED (".\count($records)." records)\n";
}

function test_read_simple_avro(): void {
  echo "Testing read simple.avro: ";

  $data = \file_get_contents(__DIR__.'/simple.avro');
  $reader = ReadFile($data);

  $schema = $reader->getSchema();
  assert_eq($schema->name, 'simple', 'schema name');

  $records = $reader->readAll();
  if (\count($records) === 0) {
    throw new \Exception("expected at least one record");
  }

  $r0 = $records[0];
  if (!($r0 is dict<_, _>)) {
    throw new \Exception("expected dict");
  }
  if (!\array_key_exists('text', $r0)) {
    throw new \Exception("expected text field");
  }

  echo "PASSED (".\count($records)." records)\n";
}

function test_write_read_container_roundtrip(): void {
  echo "Testing write/read container round-trip: ";

  $schema = ParseSchema('{
    "type": "record", "name": "TestRecord", "fields": [
      {"name": "id", "type": "long"},
      {"name": "name", "type": "string"},
      {"name": "tags", "type": {"type": "array", "items": "string"}},
      {"name": "score", "type": ["null", "double"]}
    ]
  }');

  $records = vec[
    dict['id' => 1, 'name' => 'first', 'tags' => vec['a', 'b'], 'score' => 9.5],
    dict['id' => 2, 'name' => 'second', 'tags' => vec[], 'score' => null],
    dict['id' => 3, 'name' => 'third', 'tags' => vec['x'], 'score' => 0.0],
  ];

  $avro_bytes = WriteFile($schema, $records);

  // Verify we can read back what we wrote
  $reader = ReadFile($avro_bytes);
  $decoded = $reader->readAll();

  assert_eq(\count($decoded), 3, 'record count');

  $r0 = $decoded[0];
  if (!($r0 is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($r0['id'], 1, 'id');
  assert_eq($r0['name'], 'first', 'name');
  assert_eq($r0['score'], 9.5, 'score');

  $r1 = $decoded[1];
  if (!($r1 is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($r1['score'], null, 'null score');

  echo "PASSED\n";
}

function test_interop_schema_roundtrip(): void {
  echo "Testing interop schema write/read: ";

  $schema_json = \file_get_contents(__DIR__.'/interop.avsc');
  $schema = ParseSchema($schema_json);

  $datum = dict[
    'intField' => -42,
    'longField' => 2147483650,
    'stringField' => 'hello avro',
    'boolField' => true,
    'floatField' => 1234.0,
    'doubleField' => -5432.6,
    'bytesField' => "\x16\xa6",
    'nullField' => null,
    'arrayField' => vec[5.0, -6.0, -10.5],
    'mapField' => dict[
      'a' => dict['label' => 'a'],
      'c' => dict['label' => '3P0'],
    ],
    'unionField' => 14.5,
    'enumField' => 'C',
    'fixedField' => '1019181716151413',
    'recordField' => dict[
      'label' => 'blah',
      'children' => vec[
        dict['label' => 'inner', 'children' => vec[]],
      ],
    ],
  ];

  // Binary round-trip
  $encoded = Marshal($schema, $datum);
  $decoded = Unmarshal($schema, $encoded);
  if (!($decoded is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($decoded['intField'], -42, 'intField');
  assert_eq($decoded['longField'], 2147483650, 'longField');
  assert_eq($decoded['stringField'], 'hello avro', 'stringField');
  assert_eq($decoded['boolField'], true, 'boolField');
  assert_eq($decoded['bytesField'], "\x16\xa6", 'bytesField');
  assert_eq($decoded['enumField'], 'C', 'enumField');
  assert_eq($decoded['fixedField'], '1019181716151413', 'fixedField');

  // Container file round-trip
  $avro_bytes = WriteFile($schema, vec[$datum]);
  $reader = ReadFile($avro_bytes);
  $records = $reader->readAll();
  assert_eq(\count($records), 1, 'container record count');

  $r = $records[0];
  if (!($r is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($r['intField'], -42, 'container intField');
  assert_eq($r['stringField'], 'hello avro', 'container stringField');
  assert_eq($r['enumField'], 'C', 'container enumField');

  echo "PASSED\n";
}
