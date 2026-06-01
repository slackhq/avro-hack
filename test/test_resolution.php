<?hh // strict

<<__EntryPoint>>
function test_resolution_main(): void {
  require "lib/errors.php";
  require "lib/avro.php";
  require "lib/resolution.php";

  echo "=== Schema Resolution Tests ===\n\n";

  testFieldAddedWithDefault();
  testFieldRemoved();
  testFieldReordered();
  testTypePromotion();
  testEnumResolution();
  testUnionResolution();
  testNestedRecordResolution();
  testIncompatibleSchemas();
  testArrayResolution();
  testMapResolution();

  echo "\n=== ALL RESOLUTION TESTS PASSED ===\n";
}

function res_aeq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function res_a(mixed $got, mixed $exp, string $msg): void {
  if ($got != $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function testFieldAddedWithDefault(): void {
  echo "Testing field added with default: ";

  // Writer has: name, age
  $writer = Avro\ParseSchema('{
    "type": "record", "name": "User",
    "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"}
    ]
  }');

  // Reader has: name, age, email (with default)
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "User",
    "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"},
      {"name": "email", "type": "string", "default": "unknown@example.com"}
    ]
  }');

  // Write with writer schema
  $data = dict['name' => 'Alice', 'age' => 30];
  $encoded = Avro\Marshal($writer, $data);

  // Read with reader schema (schema resolution)
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded['name'], 'Alice', 'name preserved');
  res_aeq($decoded['age'], 30, 'age preserved');
  res_aeq($decoded['email'], 'unknown@example.com', 'email gets default');

  echo "PASSED\n";
}

function testFieldRemoved(): void {
  echo "Testing field removed: ";

  // Writer has: name, age, phone
  $writer = Avro\ParseSchema('{
    "type": "record", "name": "User",
    "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"},
      {"name": "phone", "type": "string"}
    ]
  }');

  // Reader has: name, age (phone removed)
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "User",
    "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"}
    ]
  }');

  $data = dict['name' => 'Bob', 'age' => 25, 'phone' => '555-0100'];
  $encoded = Avro\Marshal($writer, $data);

  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded['name'], 'Bob', 'name preserved');
  res_aeq($decoded['age'], 25, 'age preserved');
  res_aeq(\array_key_exists('phone', $decoded), false, 'phone removed');

  echo "PASSED\n";
}

function testFieldReordered(): void {
  echo "Testing field reordered: ";

  // Writer order: a, b, c
  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [
      {"name": "a", "type": "string"},
      {"name": "b", "type": "int"},
      {"name": "c", "type": "string"}
    ]
  }');

  // Reader order: c, a, b
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [
      {"name": "c", "type": "string"},
      {"name": "a", "type": "string"},
      {"name": "b", "type": "int"}
    ]
  }');

  $data = dict['a' => 'hello', 'b' => 42, 'c' => 'world'];
  $encoded = Avro\Marshal($writer, $data);

  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded['a'], 'hello', 'a preserved');
  res_aeq($decoded['b'], 42, 'b preserved');
  res_aeq($decoded['c'], 'world', 'c preserved');

  echo "PASSED\n";
}

function testTypePromotion(): void {
  echo "Testing type promotion: ";

  // int -> long
  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": "int"}]
  }');
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": "long"}]
  }');

  $data = dict['value' => 42];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded['value'], 42, 'int->long promotion');

  // int -> double
  $reader2 = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": "double"}]
  }');
  $decoded2 = Avro\Resolution\UnmarshalWithSchema($writer, $reader2, $encoded);
  if (!($decoded2 is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded2['value'], 42.0, 'int->double promotion');

  // float -> double
  $writer_f = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": "float"}]
  }');
  $reader_d = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": "double"}]
  }');
  $data_f = dict['value' => 3.14];
  $encoded_f = Avro\Marshal($writer_f, $data_f);
  $decoded_f = Avro\Resolution\UnmarshalWithSchema($writer_f, $reader_d, $encoded_f);
  if (!($decoded_f is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  $diff = \abs((float)$decoded_f['value'] - 3.14);
  if ($diff > 0.01) {
    throw new Exception("float->double promotion failed, diff: ".$diff);
  }

  echo "PASSED\n";
}

function testEnumResolution(): void {
  echo "Testing enum resolution: ";

  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "color", "type": {
      "type": "enum", "name": "Color", "symbols": ["RED", "GREEN", "BLUE"]
    }}]
  }');

  // Reader has same enum (valid)
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "color", "type": {
      "type": "enum", "name": "Color", "symbols": ["RED", "GREEN", "BLUE", "YELLOW"]
    }}]
  }');

  $data = dict['color' => 'GREEN'];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  res_aeq($decoded['color'], 'GREEN', 'enum resolution');

  echo "PASSED\n";
}

function testUnionResolution(): void {
  echo "Testing union resolution: ";

  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": ["null", "string"]}]
  }');
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "value", "type": ["null", "string", "int"]}]
  }');

  // Test null
  $data = dict['value' => null];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);
  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");
  res_aeq($decoded['value'], null, 'union null');

  // Test string
  $data = dict['value' => 'hello'];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);
  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");
  res_aeq($decoded['value'], 'hello', 'union string');

  echo "PASSED\n";
}

function testNestedRecordResolution(): void {
  echo "Testing nested record resolution: ";

  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Outer",
    "fields": [
      {"name": "id", "type": "int"},
      {"name": "inner", "type": {
        "type": "record", "name": "Inner",
        "fields": [
          {"name": "x", "type": "int"},
          {"name": "y", "type": "int"}
        ]
      }}
    ]
  }');

  // Reader adds field to inner record
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Outer",
    "fields": [
      {"name": "id", "type": "int"},
      {"name": "inner", "type": {
        "type": "record", "name": "Inner",
        "fields": [
          {"name": "x", "type": "int"},
          {"name": "y", "type": "int"},
          {"name": "z", "type": "int", "default": 0}
        ]
      }}
    ]
  }');

  $data = dict['id' => 1, 'inner' => dict['x' => 10, 'y' => 20]];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");
  res_aeq($decoded['id'], 1, 'outer id');
  $inner = $decoded['inner'];
  if (!($inner is dict<_, _>)) throw new Exception("expected dict for inner");
  res_aeq($inner['x'], 10, 'inner x');
  res_aeq($inner['y'], 20, 'inner y');
  res_aeq($inner['z'], 0, 'inner z gets default');

  echo "PASSED\n";
}

function testIncompatibleSchemas(): void {
  echo "Testing incompatible schemas: ";

  // string -> int is not allowed
  $writer = Avro\ParseSchema('"string"');
  $reader = Avro\ParseSchema('"int"');

  try {
    Avro\Resolution\Resolve($writer, $reader);
    throw new Exception("expected exception for incompatible schemas");
  } catch (\AvroException $_) {
    // expected
  }

  // int -> string is not allowed
  $writer2 = Avro\ParseSchema('"int"');
  $reader2 = Avro\ParseSchema('"string"');

  try {
    Avro\Resolution\Resolve($writer2, $reader2);
    throw new Exception("expected exception for int->string");
  } catch (\AvroException $_) {
    // expected
  }

  echo "PASSED\n";
}

function testArrayResolution(): void {
  echo "Testing array resolution with item promotion: ";

  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "values", "type": {"type": "array", "items": "int"}}]
  }');
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "values", "type": {"type": "array", "items": "long"}}]
  }');

  $data = dict['values' => vec[1, 2, 3]];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");
  $values = $decoded['values'];
  if (!($values is vec<_>)) throw new Exception("expected vec");
  res_a($values, vec[1, 2, 3], 'array items');

  echo "PASSED\n";
}

function testMapResolution(): void {
  echo "Testing map resolution: ";

  $writer = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "data", "type": {"type": "map", "values": "int"}}]
  }');
  $reader = Avro\ParseSchema('{
    "type": "record", "name": "Rec",
    "fields": [{"name": "data", "type": {"type": "map", "values": "long"}}]
  }');

  $data = dict['data' => dict['a' => 1, 'b' => 2]];
  $encoded = Avro\Marshal($writer, $data);
  $decoded = Avro\Resolution\UnmarshalWithSchema($writer, $reader, $encoded);

  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");
  $map = $decoded['data'];
  if (!($map is dict<_, _>)) throw new Exception("expected dict for map");
  res_a($map, dict['a' => 1, 'b' => 2], 'map values');

  echo "PASSED\n";
}
