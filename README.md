# php-dcg-codec

This repository is a PHP implementation of the [Digimon Card Game (2020)](https://world.digimoncard.com/) deck codec. The original reference implementation is hosted at [niamu/digimon-card-game](https://github.com/niamu/digimon-card-game).

## Usage

### Decode

```PHP
<?php

require "src/dcg/codec/decode.php";

DCGDeckDecoder::Decode("DCGAdYlU1Q4IEHBQlQxIIPEB8UCQVNUMiBBRQNTVDggS8LBQUHBwcHBQcHBU3RhcnRlciBEZWNrLCBVbGZvcmNlVmVlZHJhbW9uIFtTVC04XQ");

?>
```

## License

Copyright © 2021 Brendon Walsh.

Licensed under the BSD 3-Clause License (see the file LICENSE).
