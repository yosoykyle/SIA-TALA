@foreach ($rows as $row)
    <div>
        <strong>{{ $row['label'] }}</strong>
        <p>{{ \App\Actions\Finance\AccountingDisplay::money($row['applied']) }} applied · {{ \App\Actions\Finance\AccountingDisplay::money($row['remaining']) }} remaining</p>
    </div>
@endforeach
