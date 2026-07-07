const FormData = require('form-data');
const fetch = require('node-fetch');

async function test() {
  const form = new FormData();
  form.append('name', 'Test Node');
  form.append('price', '15.99');
  form.append('is_active', '1');
  
  const res = await fetch('http://localhost:8000/api/v1/products', {
    method: 'POST',
    body: form,
    headers: {
      'Authorization': 'Bearer 1|UAYEvv9RYOk95zEwJRNqm2xbXsUOUViLIB6aUCXb5c1509b4',
      'Accept': 'application/json',
      // form-data library handles boundary
    }
  });
  
  console.log(res.status);
  console.log(await res.text());
}
test();
