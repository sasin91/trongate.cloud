-- 1000 returning visitors: each request carries one of 1000 session cookies, round robin
local n = 0
request = function()
  n = n + 1
  return wrk.format(nil, nil, {["Cookie"] = string.format("PHPSESSID=bench%028d", (n * 7919 + math.random(0, 999)) % 1000)})
end
